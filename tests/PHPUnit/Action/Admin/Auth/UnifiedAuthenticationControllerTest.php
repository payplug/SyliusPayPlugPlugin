<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action\Admin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Core\HttpClient;
use PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth\UnifiedAuthenticationController;
use PayPlug\SyliusPayPlugPlugin\Auth\IdTokenEmailExtractor;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Support\FakePayplugHttpRequest;

/**
 * PaymentMethodValidator is `final`, and the happy path beyond the token exchange runs through the
 * legacy Authentication::createClientIdAndSecret() static calls, which PHPUnit cannot intercept.
 * The payplug-php SDK does expose one seam for exactly this — the public static
 * HttpClient::$REQUEST_HANDLER, an IHttpRequest the SDK uses in place of cURL when set — so the
 * happy path is reached here by installing a FakePayplugHttpRequest rather than by refactoring
 * those static calls. tearDown() clears the handler again: it is process-global state, and leaving
 * it installed would silently reroute any other test that touches the SDK.
 */
final class UnifiedAuthenticationControllerTest extends TestCase
{
    private RouterInterface&MockObject $router;

    private RepositoryInterface&MockObject $paymentMethodRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private PaymentMethodValidator $paymentMethodValidator;

    private LoggerInterface&MockObject $logger;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ValidatorInterface&MockObject $validator;

    private UnifiedAuthenticationController $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->paymentMethodRepository = $this->createMock(RepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        // final class — cannot be mocked by PHPUnit, so a real instance wired with mocked
        // collaborators is built instead. Most scenarios here stop before process() is reached;
        // the happy-path ones do reach it, hence the stubbed validator returning no violations.
        $this->paymentMethodValidator = new PaymentMethodValidator(
            $this->createMock(RequestStack::class),
            $this->validator,
            $this->entityManager,
        );
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->oauthHttpClient = $this->createMock(IOAuthHttpClient::class);

        $this->controller = new UnifiedAuthenticationController(
            $this->router,
            $this->paymentMethodRepository,
            $this->entityManager,
            $this->paymentMethodValidator,
            $this->logger,
            $this->oauthHttpClient,
            new IdTokenEmailExtractor(),
            'https://api-qa.payplug.com',
            'https://www.payplug.com',
        );

        $this->controller->setContainer(new ServiceLocator([
            'router' => fn () => $this->router,
        ]));
    }

    protected function tearDown(): void
    {
        HttpClient::$REQUEST_HANDLER = null;
    }

    private function buildRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * PHPUnit resolves multiple `method('generate')->with(...)` stubs by registration order, not
     * by which constraint actually matches a given call — a single callback branching on the
     * route name is the only way to give different routes different return values reliably.
     *
     * @param array<string, string> $routeUrls route name => URL to return
     * @param array<string> $throwForRoutes route names that should throw instead
     */
    private function stubRouterGenerate(array $routeUrls, array $throwForRoutes = []): void
    {
        $this->router->method('generate')->willReturnCallback(
            function (string $route) use ($routeUrls, $throwForRoutes): string {
                if (\in_array($route, $throwForRoutes, true)) {
                    throw new \RuntimeException('router exploded for route ' . $route);
                }

                return $routeUrls[$route] ?? '/admin/payment-methods';
            },
        );
    }

    // -------------------------------------------------------------------------
    // setupRedirection() — happy path
    // -------------------------------------------------------------------------

    public function testSetupRedirection_buildsAuthorizationUrlAndStoresPkceStateInSession(): void
    {
        $this->stubRouterGenerate(['payplug_sylius_admin_auth_oauth_callback' => 'https://shop.example.com/payplug/auth/oauth-callback']);

        $request = $this->buildRequest(['client_id' => 'client_abc', 'company_id' => 'company_xyz']);

        $response = $this->controller->setupRedirection($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringStartsWith('https://api-qa.payplug.com/oauth2/auth?', $response->getTargetUrl());
        self::assertStringContainsString('client_id=client_abc', $response->getTargetUrl());
        self::assertStringContainsString('audience=' . urlencode('https://www.payplug.com'), $response->getTargetUrl());

        $session = $request->getSession();
        self::assertSame('client_abc', $session->get('payplug_client_id'));
        self::assertSame('company_xyz', $session->get('payplug_company_id'));
        self::assertNotNull($session->get('payplug_oauth_state'));
        self::assertNotNull($session->get('payplug_oauth_code_verifier'));
    }

    // -------------------------------------------------------------------------
    // setupRedirection() — failure redirects to payment method index (no id in session yet)
    // -------------------------------------------------------------------------

    public function testSetupRedirection_onFailure_logsAndRedirectsToPaymentMethodIndex(): void
    {
        $this->stubRouterGenerate(
            ['sylius_admin_payment_method_index' => '/admin/payment-methods'],
            throwForRoutes: ['payplug_sylius_admin_auth_oauth_callback'],
        );

        $this->logger->expects(self::once())->method('critical')
            ->with('Error while perform Payplug OAuth Setup redirection', self::anything())
        ;

        $request = $this->buildRequest(['client_id' => 'client_abc']);

        $response = $this->controller->setupRedirection($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('payplug_sylius_payplug_plugin.admin.oauth_setup_error', $request->getSession()->getFlashBag()->peek('error')[0] ?? null);
    }

    // -------------------------------------------------------------------------
    // oauthCallback() — state mismatch is rejected before any token exchange
    // -------------------------------------------------------------------------

    public function testOauthCallback_withMismatchedState_rejectsBeforeExchangingToken(): void
    {
        $this->stubRouterGenerate([]);
        $this->oauthHttpClient->expects(self::never())->method('post');
        $this->logger->expects(self::once())->method('critical')
            ->with('Error while perform Payplug OAuth callback', self::anything())
        ;

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'attacker-state']);
        $request->getSession()->set('payplug_oauth_state', 'real-state');

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testOauthCallback_withNoStateInSession_rejectsBeforeExchangingToken(): void
    {
        $this->stubRouterGenerate([]);
        // Session never went through setupRedirection() (e.g. expired) — no expected state at all.
        $this->oauthHttpClient->expects(self::never())->method('post');

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'some-state']);

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testOauthCallback_withEmptyState_rejectsBeforeExchangingToken(): void
    {
        $this->stubRouterGenerate([]);
        $this->oauthHttpClient->expects(self::never())->method('post');

        $request = $this->buildRequest(['code' => 'auth_code']); // no "state" query param at all
        $request->getSession()->set('payplug_oauth_state', '');

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // -------------------------------------------------------------------------
    // oauthCallback() — valid state, but no client id in session
    // -------------------------------------------------------------------------

    /**
     * A missing/non-string client_id (e.g. session expired, or setupRedirection() was never hit)
     * must be rejected before exchangeAuthorizationCode() is called, the same way a state mismatch
     * already is — otherwise it falls through to a TypeError, logged as a noisy "critical" for
     * what's really just an expired session.
     */
    public function testOauthCallback_withMissingClientId_rejectsBeforeExchangingToken(): void
    {
        $this->stubRouterGenerate([]);
        $this->oauthHttpClient->expects(self::never())->method('post');

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'matching-state']);
        $request->getSession()->set('payplug_oauth_state', 'matching-state');
        $request->getSession()->set('payplug_oauth_code_verifier', 'verifier_123');
        // Deliberately no 'payplug_client_id' set.

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // -------------------------------------------------------------------------
    // oauthCallback() — valid state, but no code verifier in session
    // -------------------------------------------------------------------------

    /**
     * A missing/non-string code_verifier (e.g. session expired, or setupRedirection() was never
     * hit) must be rejected before exchangeAuthorizationCode() is called, the same way a state
     * mismatch already is — otherwise it falls through to a TypeError, logged as a noisy
     * "critical" for what's really just an expired session.
     */
    public function testOauthCallback_withMissingCodeVerifier_rejectsBeforeExchangingToken(): void
    {
        $this->stubRouterGenerate([]);
        $this->oauthHttpClient->expects(self::never())->method('post');

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'matching-state']);
        $request->getSession()->set('payplug_oauth_state', 'matching-state');
        // Deliberately no 'payplug_oauth_code_verifier' set.

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // -------------------------------------------------------------------------
    // oauthCallback() — valid state, but no payment method id in session
    // -------------------------------------------------------------------------

    public function testOauthCallback_withValidStateButNoPaymentMethodIdInSession_stopsAfterTokenExchange(): void
    {
        $this->stubRouterGenerate(['payplug_sylius_admin_auth_oauth_callback' => 'https://shop.example.com/payplug/auth/oauth-callback']);

        $this->oauthHttpClient->expects(self::once())->method('post')->willReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'jwt', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        ]);

        // Never reached: the "no payment method id" guard throws first.
        $this->paymentMethodRepository->expects(self::never())->method('find');

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'matching-state']);
        $request->getSession()->set('payplug_client_id', 'client_abc');
        $request->getSession()->set('payplug_oauth_state', 'matching-state');
        $request->getSession()->set('payplug_oauth_code_verifier', 'verifier_123');
        // Deliberately no 'payplug_sylius_oauth_payment_method_id' set.

        $response = $this->controller->oauthCallback($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('payplug_sylius_payplug_plugin.admin.oauth_setup_error', $request->getSession()->getFlashBag()->peek('error')[0] ?? null);
    }

    // -------------------------------------------------------------------------
    // oauthCallback() — the connected account's email (PRE-3631)
    // -------------------------------------------------------------------------

    /**
     * Builds a JWT-shaped id token carrying the given claims. The signature segment is a
     * placeholder — the controller reads the payload for display and never verifies it.
     *
     * @param array<string, mixed> $claims
     */
    private function idTokenWithClaims(array $claims): string
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $encode('{"alg":"RS256"}') . '.' . $encode((string) json_encode($claims)) . '.c2ln';
    }

    /**
     * Drives oauthCallback() all the way through a successful login and returns the gateway config
     * as it stands afterwards. $tokenResponse is the identity provider's token-endpoint payload.
     *
     * @param array<string, mixed> $tokenResponse
     * @param array<array-key, mixed> $initialConfig gateway config as it stands before the login
     *
     * @return array<array-key, mixed>
     */
    private function runSuccessfulCallback(array $tokenResponse, array $initialConfig = []): array
    {
        $this->stubRouterGenerate([
            'payplug_sylius_admin_auth_oauth_callback' => 'https://shop.example.com/payplug/auth/oauth-callback',
            'sylius_admin_payment_method_update' => '/admin/payment-methods/1/edit',
        ]);

        $this->oauthHttpClient->method('post')->willReturn([
            'status' => 200,
            'body' => json_encode($tokenResponse),
        ]);

        // Answers both createClientIdAndSecret() calls (test then live) without touching the network.
        HttpClient::$REQUEST_HANDLER = new FakePayplugHttpRequest([
            json_encode(['client_id' => 'generated_client', 'client_secret' => 'generated_secret']),
        ]);

        $storedConfig = $initialConfig;
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturnCallback(static fn (): array => $storedConfig);
        $gatewayConfig->method('setConfig')->willReturnCallback(
            static function (array $config) use (&$storedConfig): void {
                $storedConfig = $config;
            },
        );

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('getName')->willReturn('Carte bancaire');
        $paymentMethod->method('getId')->willReturn(1);

        $this->paymentMethodRepository->method('find')->willReturn($paymentMethod);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $request = $this->buildRequest(['code' => 'auth_code', 'state' => 'matching-state']);
        $session = $request->getSession();
        $session->set('payplug_client_id', 'client_abc');
        $session->set('payplug_company_id', 'company_xyz');
        $session->set('payplug_oauth_state', 'matching-state');
        $session->set('payplug_oauth_code_verifier', 'verifier_123');
        $session->set('payplug_sylius_oauth_payment_method_id', 1);

        $this->logger->expects(self::never())->method('critical');

        $this->controller->oauthCallback($request);

        return $storedConfig;
    }

    /**
     * The `/account` endpoint carries no email — the id_token from this exchange is the only place
     * the connected merchant's address appears, and it is gone once the callback returns, so it has
     * to be persisted here for the admin screen to have anything to show.
     */
    public function testOauthCallback_storesTheEmailFromTheIdTokenOnTheGatewayConfig(): void
    {
        $config = $this->runSuccessfulCallback([
            'access_token' => 'jwt',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'id_token' => $this->idTokenWithClaims(['email' => 'merchant@example.com']),
        ]);

        self::assertSame('merchant@example.com', $config['account_email'] ?? null);
    }

    /**
     * The credentials are what the login exists to produce; the email is a display nicety captured
     * alongside them. An identity provider that returns no id_token must therefore still yield a
     * fully configured gateway.
     */
    public function testOauthCallback_withoutAnIdToken_stillStoresTheClientCredentials(): void
    {
        $config = $this->runSuccessfulCallback([
            'access_token' => 'jwt',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]);

        self::assertNull($config['account_email'] ?? null);
        self::assertSame('generated_client', $config['live_client']['client_id'] ?? null);
        self::assertSame('generated_client', $config['test_client']['client_id'] ?? null);
    }

    /**
     * Re-authenticating against a different PayPlug account must not leave the previous account's
     * address on screen — a stale email here would misreport which account takes the money.
     */
    public function testOauthCallback_overwritesAPreviouslyStoredEmail(): void
    {
        $config = $this->runSuccessfulCallback(
            [
                'access_token' => 'jwt',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'id_token' => $this->idTokenWithClaims(['email' => 'new-owner@example.com']),
            ],
            ['account_email' => 'previous-owner@example.com'],
        );

        self::assertSame('new-owner@example.com', $config['account_email'] ?? null);
    }
}
