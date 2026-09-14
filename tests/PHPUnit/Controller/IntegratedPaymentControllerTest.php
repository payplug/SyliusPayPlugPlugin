<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Payplug\Resource\Payment as PayplugPayment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Controller\IntegratedPaymentController;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class IntegratedPaymentControllerTest extends TestCase
{
    private RepositoryInterface&MockObject $paymentMethodRepository;

    private CartContextInterface&MockObject $cartContext;

    private PayPlugPaymentDataCreator&MockObject $paymentDataCreator;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private IntegratedPaymentController $controller;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(RepositoryInterface::class);
        $this->cartContext = $this->createMock(CartContextInterface::class);
        $this->paymentDataCreator = $this->createMock(PayPlugPaymentDataCreator::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);

        $this->controller = new IntegratedPaymentController(
            $this->cartContext,
            $this->paymentMethodRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->paymentDataCreator,
            $this->apiClientFactory,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * The iframe posts a payment method id, so the account the payment is created on must be the
     * one configured on *that* payment method. Resolving the client by factory name instead would
     * pick an arbitrary one of the several gateway configs that may now share it — one per channel
     * since PRE-3628 — and create the payment on another channel's PayPlug account.
     */
    public function testInitPayment_createsThePaymentOnTheAccountOfTheSubmittedPaymentMethod(): void
    {
        $paymentMethod = $this->paymentMethodWithFactoryName(PayPlugGatewayFactory::FACTORY_NAME);
        $this->paymentMethodRepository->method('find')->with(42)->willReturn($paymentMethod);

        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $this->cartContext->method('getCart')->willReturn($order);

        $this->paymentDataCreator->method('create')->willReturn(new ArrayObject());

        $payplugPayment = $this->createMock(PayplugPayment::class);
        $payplugPayment->id = 'pay_1';
        $payplugPayment->is_live = false;

        $apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $apiClient->method('createPayment')->willReturn($payplugPayment);

        $this->apiClientFactory->expects(self::once())
            ->method('createForPaymentMethod')
            ->with(self::identicalTo($paymentMethod))
            ->willReturn($apiClient);

        $response = $this->controller->initPaymentAction(Request::create('/payplug/integrated-payment/init/42'), 42);

        self::assertSame(201, $response->getStatusCode());
    }

    private function paymentMethodWithFactoryName(string $factoryName): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }
}
