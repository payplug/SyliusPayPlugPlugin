<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\EventListener\PostSavePaymentMethodEventListener;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefreshResult;
use PayPlug\SyliusPayPlugPlugin\Validator\PaymentMethodValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PostSavePaymentMethodEventListenerTest extends TestCase
{
    private PproConfigurationRefresherInterface&MockObject $refresher;

    private PostSavePaymentMethodEventListener $listener;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->refresher = $this->createMock(PproConfigurationRefresherInterface::class);

        $this->listener = new PostSavePaymentMethodEventListener(
            $requestStack,
            $this->createMock(RouterInterface::class),
            new PaymentMethodValidator($requestStack, $validator, $this->createMock(EntityManagerInterface::class)),
            new NullLogger(),
            $this->refresher,
        );
    }

    public function testSaveThatSwitchesTheEnvironmentRefreshesThePproLimits(): void
    {
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'live' => true,
            'payment_method' => ['name' => 'wero', 'live' => false],
        ]);

        $this->refresher->expects(self::once())->method('refresh')->with($paymentMethod)->willReturn(new PproRefreshResult(true));

        $this->listener->onUpdate(new ResourceControllerEvent($paymentMethod));
    }

    public function testSaveInTheSameEnvironmentDoesNotRefresh(): void
    {
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'live' => true,
            'payment_method' => ['name' => 'wero', 'live' => true],
        ]);

        $this->refresher->expects(self::never())->method('refresh');

        $this->listener->onUpdate(new ResourceControllerEvent($paymentMethod));
    }

    public function testSaveWithoutStoredLimitsDoesNotRefresh(): void
    {
        $paymentMethod = $this->paymentMethod(WeroGatewayFactory::FACTORY_NAME, ['live' => true]);

        $this->refresher->expects(self::never())->method('refresh');

        $this->listener->onUpdate(new ResourceControllerEvent($paymentMethod));
    }

    public function testNonPproSaveDoesNotRefresh(): void
    {
        $paymentMethod = $this->paymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [
            'live' => true,
            'payment_method' => ['live' => false],
        ]);

        $this->refresher->expects(self::never())->method('refresh');

        $this->listener->onUpdate(new ResourceControllerEvent($paymentMethod));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(string $factoryName, array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
