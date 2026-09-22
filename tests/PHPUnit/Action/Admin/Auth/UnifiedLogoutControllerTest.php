<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action\Admin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth\UnifiedLogoutController;
use PayPlug\SyliusPayPlugPlugin\Auth\GatewayConnectionRevoker;
use PayPlug\SyliusPayPlugPlugin\Auth\SyliusTokenCache;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The action is reachable by GET because its button is rendered inside the Sylius payment-method
 * form, where a nested <form> would be invalid HTML — so the CSRF check is the only thing standing
 * between a crafted link and a merchant losing a connection. Several tests below exist purely to
 * pin that: a request that fails the check must mutate nothing at all.
 */
final class UnifiedLogoutControllerTest extends TestCase
{
    private const VALID_TOKEN = 'a-valid-csrf-token';

    private RouterInterface&MockObject $router;

    private RepositoryInterface&MockObject $paymentMethodRepository;

    private LoggerInterface&MockObject $logger;

    private SyliusTokenCache $tokenCache;

    private UnifiedLogoutController $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->paymentMethodRepository = $this->createMock(RepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tokenCache = new SyliusTokenCache(new ArrayAdapter());

        $this->router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/admin/route/' . $route . '/' . ($parameters['id'] ?? ''),
        );

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => self::VALID_TOKEN === $token->getValue(),
        );

        $this->controller = new UnifiedLogoutController(
            $this->router,
            $this->paymentMethodRepository,
            new GatewayConnectionRevoker($this->createMock(EntityManagerInterface::class), $this->tokenCache),
            $csrfTokenManager,
            $this->logger,
        );
    }

    public function testLogout_clearsTheConnectionOfTheTargetedGatewayConfig(): void
    {
        $paymentMethod = $this->connectedPaymentMethod();
        $this->paymentMethodRepository->method('find')->with(42)->willReturn($paymentMethod);

        $this->controller->logout($this->buildRequest(self::VALID_TOKEN), 42);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        self::assertArrayNotHasKey('live_client', $config);
        self::assertArrayNotHasKey('test_client', $config);
        self::assertArrayNotHasKey('account_email', $config);
        self::assertFalse($paymentMethod->isEnabled());
    }

    public function testLogout_redirectsBackToThePaymentMethodUpdateScreenWithASuccessFlash(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->connectedPaymentMethod());
        $request = $this->buildRequest(self::VALID_TOKEN);

        $response = $this->controller->logout($request, 42);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/route/sylius_admin_payment_method_update/42', $response->getTargetUrl());
        self::assertSame(
            'payplug_sylius_payplug_plugin.admin.logout_success',
            $request->getSession()->getFlashBag()->peek('success')[0] ?? null,
        );
    }

    public function testLogout_invalidCsrfToken_isRejectedAndChangesNothing(): void
    {
        $paymentMethod = $this->connectedPaymentMethod();
        $this->paymentMethodRepository->method('find')->willReturn($paymentMethod);

        try {
            $this->controller->logout($this->buildRequest('forged-token'), 42);
            self::fail('Expected a BadRequestHttpException for an invalid CSRF token.');
        } catch (BadRequestHttpException) {
            // expected
        }

        self::assertArrayHasKey('live_client', $paymentMethod->getGatewayConfig()?->getConfig() ?? []);
        self::assertTrue($paymentMethod->isEnabled());
    }

    public function testLogout_missingCsrfToken_isRejected(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->connectedPaymentMethod());

        $this->expectException(BadRequestHttpException::class);

        $this->controller->logout($this->buildRequest(null), 42);
    }

    /**
     * `security.csrf.token_manager` only exists while CSRF protection is enabled, so the dependency
     * is optional and the check is skipped when it is absent — the same posture as Sylius's own
     * token-in-the-query-string admin actions, which guard every check with
     * sylius_csrf_protection_enabled(). An app that turns CSRF off has made that call for itself;
     * the button must still work there rather than 400 on every click.
     */
    public function testLogout_whenCsrfProtectionIsDisabled_proceedsWithoutAToken(): void
    {
        $paymentMethod = $this->connectedPaymentMethod();
        $this->paymentMethodRepository->method('find')->willReturn($paymentMethod);

        $controller = new UnifiedLogoutController(
            $this->router,
            $this->paymentMethodRepository,
            new GatewayConnectionRevoker($this->createMock(EntityManagerInterface::class), $this->tokenCache),
            null,
            $this->logger,
        );

        $controller->logout($this->buildRequest(null), 42);

        self::assertArrayNotHasKey('live_client', $paymentMethod->getGatewayConfig()?->getConfig() ?? []);
        self::assertFalse($paymentMethod->isEnabled());
    }

    public function testLogout_unknownPaymentMethod_isNotFound(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->controller->logout($this->buildRequest(self::VALID_TOKEN), 42);
    }

    public function testLogout_paymentMethodOfAnotherProvider_isNotFound(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->connectedPaymentMethod('stripe'));

        $this->expectException(NotFoundHttpException::class);

        $this->controller->logout($this->buildRequest(self::VALID_TOKEN), 42);
    }

    public function testLogout_whenRevokingFails_logsAndRedirectsWithAnErrorFlash(): void
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($this->gatewayConfig(PayPlugGatewayFactory::FACTORY_NAME));
        $this->paymentMethodRepository->method('find')->willReturn($paymentMethod);

        $this->logger->expects(self::once())->method('critical')
            ->with('Error while logging out the Payplug gateway', self::anything())
        ;

        $request = $this->buildRequest(self::VALID_TOKEN);
        $response = $this->controllerWithFailingRevoker()->logout($request, 42);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            'payplug_sylius_payplug_plugin.admin.logout_error',
            $request->getSession()->getFlashBag()->peek('error')[0] ?? null,
        );
    }

    /**
     * GatewayConnectionRevoker is `final` and cannot be mocked, so the failure is injected one
     * level down: a real revoker over an entity manager whose flush() throws, which is what a
     * database error during logout actually looks like.
     */
    private function controllerWithFailingRevoker(): UnifiedLogoutController
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(new \RuntimeException('database exploded'));

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        return new UnifiedLogoutController(
            $this->router,
            $this->paymentMethodRepository,
            new GatewayConnectionRevoker($entityManager, $this->tokenCache),
            $csrfTokenManager,
            $this->logger,
        );
    }

    private function buildRequest(?string $csrfToken): Request
    {
        $request = new Request(null === $csrfToken ? [] : ['_csrf_token' => $csrfToken]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function connectedPaymentMethod(
        string $factoryName = PayPlugGatewayFactory::FACTORY_NAME,
    ): PaymentMethodInterface
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($this->gatewayConfig($factoryName));
        $paymentMethod->enable();

        return $paymentMethod;
    }

    private function gatewayConfig(string $factoryName): GatewayConfig
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setConfig([
            'live_client' => ['client_id' => 'client_live', 'client_secret' => 'secret_live'],
            'test_client' => ['client_id' => 'client_test', 'client_secret' => 'secret_test'],
            'account_email' => 'merchant@example.com',
        ]);

        return $gatewayConfig;
    }
}
