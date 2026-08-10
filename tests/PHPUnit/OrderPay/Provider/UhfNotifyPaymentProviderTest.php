<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\OrderPay\Provider;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\OrderPay\Provider\UhfNotifyPaymentProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;

final class UhfNotifyPaymentProviderTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    private UhfNotifyPaymentProvider $provider;

    private PaymentMethodInterface&MockObject $uhfMethod;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->provider = new UhfNotifyPaymentProvider($this->orderRepository);

        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(UhfGatewayFactory::FACTORY_NAME);
        $this->uhfMethod = $this->createMock(PaymentMethodInterface::class);
        $this->uhfMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
    }

    private function requestWithPayload(array $payload): Request
    {
        return Request::create('/payment-methods/uhf_code', 'POST', [], [], [], [], \json_encode($payload));
    }

    // -------------------------------------------------------------------------
    // supports()
    // -------------------------------------------------------------------------

    public function testSupports_withUnifiedApiWebhookShapeAndUhfMethod_returnsTrue(): void
    {
        $request = $this->requestWithPayload(['id' => 'pay_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        self::assertTrue($this->provider->supports($request, $this->uhfMethod));
    }

    public function testSupports_withLegacyPayplugWebhookShape_returnsFalse(): void
    {
        $request = $this->requestWithPayload(['id' => 'pay_1', 'object' => 'payment', 'metadata' => ['order_number' => '42']]);

        self::assertFalse($this->provider->supports($request, $this->uhfMethod));
    }

    public function testSupports_forANonUhfPaymentMethod_returnsFalse(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn('payplug_scalapay');
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $request = $this->requestWithPayload(['id' => 'pay_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        self::assertFalse($this->provider->supports($request, $method));
    }

    // -------------------------------------------------------------------------
    // getPayment()
    // -------------------------------------------------------------------------

    public function testGetPayment_findsTheOrderAndThePaymentMatchingTheOperationId(): void
    {
        $matchingPayment = $this->createMock(PaymentInterface::class);
        $matchingPayment->method('getDetails')->willReturn(['payment_id' => 'pay_1']);
        $otherPayment = $this->createMock(PaymentInterface::class);
        $otherPayment->method('getDetails')->willReturn(['payment_id' => 'pay_0']);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayments')->willReturn(new ArrayCollection([$otherPayment, $matchingPayment]));

        $this->orderRepository->method('findOneBy')->with(['id' => '42'])->willReturn($order);

        $request = $this->requestWithPayload(['id' => 'pay_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        self::assertSame($matchingPayment, $this->provider->getPayment($request, $this->uhfMethod));
    }

    public function testGetPayment_whenOrderNotFound_throwsInvalidArgumentException(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);

        $request = $this->requestWithPayload(['id' => 'pay_1', 'execCode' => '0000', 'orderId' => '999', 'amount' => 1000]);

        $this->expectException(\InvalidArgumentException::class);

        $this->provider->getPayment($request, $this->uhfMethod);
    }

    public function testGetPayment_whenNoPaymentMatchesTheOperationId_throwsInvalidArgumentException(): void
    {
        $otherPayment = $this->createMock(PaymentInterface::class);
        $otherPayment->method('getDetails')->willReturn(['payment_id' => 'pay_0']);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayments')->willReturn(new ArrayCollection([$otherPayment]));

        $this->orderRepository->method('findOneBy')->willReturn($order);

        $request = $this->requestWithPayload(['id' => 'pay_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        $this->expectException(\InvalidArgumentException::class);

        $this->provider->getPayment($request, $this->uhfMethod);
    }
}
