<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\EventListener;

use PayPlug\SyliusPayPlugPlugin\EventListener\PproConfigRefreshOnAdminDisplayListener;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefreshResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PproConfigRefreshOnAdminDisplayListenerTest extends TestCase
{
    private RepositoryInterface&MockObject $paymentMethodRepository;

    private PproConfigurationRefresherInterface&MockObject $refresher;

    private PproConfigRefreshOnAdminDisplayListener $listener;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(RepositoryInterface::class);
        $this->refresher = $this->createMock(PproConfigurationRefresherInterface::class);

        $this->listener = new PproConfigRefreshOnAdminDisplayListener($this->paymentMethodRepository, $this->refresher);
    }

    public function testRefreshesOnGetOfTheUpdateRoute(): void
    {
        $paymentMethod = $this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME);
        $this->paymentMethodRepository->method('find')->with('7')->willReturn($paymentMethod);
        $this->refresher->expects(self::once())->method('refreshIfStale')->with($paymentMethod, true)->willReturn(new PproRefreshResult(true));

        $request = $this->request();
        ($this->listener)($this->event($request));

        self::assertSame([], $request->getSession()->getFlashBag()->peekAll());
    }

    public function testIgnoresPost(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME));
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request(method: 'POST')));
    }

    public function testIgnoresOtherRoutes(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('find');
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request(route: 'sylius_admin_payment_method_index')));
    }

    public function testIgnoresNonPproPaymentMethod(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(OneyGatewayFactory::FACTORY_NAME));
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request()));
    }

    /**
     * A payment method never connected to PayPlug has no limits to fetch: it is not an outage, so
     * there is no API call and no warning.
     */
    public function testIgnoresNeverConnectedPaymentMethod(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME, false));
        $this->refresher->expects(self::never())->method('refreshIfStale');

        $request = $this->request();
        ($this->listener)($this->event($request));

        self::assertSame([], $request->getSession()->getFlashBag()->peekAll());
    }

    public function testIgnoresPaymentMethodWithoutGatewayConfig(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn(new PaymentMethod());
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request()));
    }

    public function testIgnoresUnknownId(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn(null);
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request()));
    }

    public function testIgnoresMissingId(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('find');
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request(id: null)));
    }

    public function testFailureAddsWarningFlashAndDoesNotThrow(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME));
        $this->refresher->method('refreshIfStale')->willReturn(PproRefreshResult::notRefreshed());

        $request = $this->request();
        ($this->listener)($this->event($request));

        self::assertSame(
            ['payplug_sylius_payplug_plugin.ppro.refresh_failed'],
            $request->getSession()->getFlashBag()->peek('warning'),
        );
    }

    public function testAdjustedResultAddsInfoFlash(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME));
        $this->refresher->method('refreshIfStale')->willReturn(new PproRefreshResult(true, true));

        $request = $this->request();
        ($this->listener)($this->event($request));

        self::assertSame(
            ['payplug_sylius_payplug_plugin.ppro.settings_adjusted'],
            $request->getSession()->getFlashBag()->peek('info'),
        );
    }

    /**
     * Limits refreshed a few minutes ago are shown as they are: no PayPlug call, no write, no flash.
     */
    public function testRecentLimitsAddNoFlash(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME));
        $this->refresher->expects(self::never())->method('refresh');
        $this->refresher->expects(self::once())->method('refreshIfStale')->willReturn(PproRefreshResult::upToDate());

        $request = $this->request();
        ($this->listener)($this->event($request));

        self::assertSame([], $request->getSession()->getFlashBag()->peekAll());
    }

    public function testRequestWithoutSessionStillRefreshes(): void
    {
        $this->paymentMethodRepository->method('find')->willReturn($this->paymentMethod(BancontactGatewayFactory::FACTORY_NAME));
        $this->refresher->expects(self::once())->method('refreshIfStale')->willReturn(PproRefreshResult::notRefreshed());

        $request = new Request([], [], ['_route' => 'sylius_admin_payment_method_update', 'id' => '7']);
        ($this->listener)($this->event($request));
    }

    public function testSubRequestIsIgnored(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('find');
        $this->refresher->expects(self::never())->method('refreshIfStale');

        ($this->listener)($this->event($this->request(), HttpKernelInterface::SUB_REQUEST));
    }

    private function request(
        string $method = 'GET',
        string $route = 'sylius_admin_payment_method_update',
        ?string $id = '7',
    ): Request {
        $attributes = ['_route' => $route];
        if (null !== $id) {
            $attributes['id'] = $id;
        }

        $request = new Request([], [], $attributes);
        $request->setMethod($method);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            $requestType,
        );
    }

    private function paymentMethod(string $factoryName, bool $connected = true): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setConfig($connected ? ['test_client' => ['client_id' => 'id', 'client_secret' => 'secret']] : []);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
