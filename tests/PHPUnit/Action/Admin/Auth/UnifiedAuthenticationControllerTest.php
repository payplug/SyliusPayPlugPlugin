<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action\Admin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth\UnifiedAuthenticationController;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PaymentMethodValidator is `final` and only reached after the legacy
 * Authentication::createClientIdAndSecret() static calls in oauthCallback() — calls that cannot
 * be intercepted by PHPUnit (static methods on a vendor SDK class, not mockable). Coverage here
 * therefore stops at that boundary: everything up to and including the token exchange and the
 * "no payment method id in session" guard is covered; the createClientIdAndSecret()-and-beyond
 * happy path is not unit-testable without refactoring that legacy call behind an abstraction,
 * which is out of scope for this migration.
 */
final class UnifiedAuthenticationControllerTest extends TestCase
{
    private RouterInterface&MockObject $router;

    private RepositoryInterface&MockObject $paymentMethodRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private PaymentMethodValidator $paymentMethodValidator;

    private LoggerInterface&MockObject $logger;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private UnifiedAuthenticationController $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->paymentMethodRepository = $this->createMock(RepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        // final class — cannot be mocked by PHPUnit. Its process() method is never reached by
        // the scenarios covered here (they all stop before that point), so a real instance
        // wired with mocked collaborators is built purely to satisfy the constructor type-hint.
        $this->paymentMethodValidator = new PaymentMethodValidator(
            $this->createMock(RequestStack::class),
            $this->createMock(ValidatorInterface::class),
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
            'https://api-qa.payplug.com',
            'https://www.payplug.com',
        );

        $this->controller->setContainer(new ServiceLocator([
            'router' => fn () => $this->router,
        ]));
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
}
