<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Upc\OrderAddressDtoCreator;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureContextBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class PaymentCaptureContextBuilderTest extends TestCase
{
    private UrlGeneratorInterface&MockObject $urlGenerator;

    private UrlProviderInterface&MockObject $afterPayUrlProvider;

    private RequestStack $requestStack;

    private PaymentCaptureContextBuilder $builder;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $this->afterPayUrlProvider->method('getUrl')->willReturn('https://shop.test/order/00000042/pay');
        $this->requestStack = new RequestStack();

        $this->builder = new PaymentCaptureContextBuilder(
            $this->urlGenerator,
            $this->afterPayUrlProvider,
            new OrderAddressDtoCreator(),
            $this->requestStack,
        );
    }

    private function methodWithGatewayConfig(?array $config): PaymentMethodInterface&MockObject
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        if (null !== $config) {
            $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
            $gatewayConfig->method('getConfig')->willReturn($config);
            $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        }

        return $method;
    }

    public function testResolveGatewayCredentials_withCompleteConfig_returnsAccountIdAndSubmerchantId(): void
    {
        $method = $this->methodWithGatewayConfig(['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1']);

        self::assertSame(['acct_123', 'sub_ext_1'], $this->builder->resolveGatewayCredentials($method));
    }

    public function testResolveGatewayCredentials_withNoGatewayConfig_throws(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder->resolveGatewayCredentials($this->methodWithGatewayConfig(null));
    }

    public function testResolveGatewayCredentials_withBlankSubmerchantId_throws(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder->resolveGatewayCredentials($this->methodWithGatewayConfig(['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => '']));
    }

    public function testResolvePaymentMethod_withNoMethodOnThePayment_throws(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->builder->resolvePaymentMethod($payment);
    }

    public function testResolvePaymentMethod_withAMethodOnThePayment_returnsIt(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);

        self::assertSame($method, $this->builder->resolvePaymentMethod($payment));
    }

    public function testResolveAmountAndCurrency_withAmountOrCurrencyMissing_throws(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn(null);
        $payment->method('getCurrencyCode')->willReturn('EUR');

        $this->expectException(\LogicException::class);

        $this->builder->resolveAmountAndCurrency($payment);
    }

    public function testResolveAmountAndCurrency_withBothSet_returnsThem(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');

        self::assertSame([1000, 'EUR'], $this->builder->resolveAmountAndCurrency($payment));
    }

    public function testBuildCustomerDto_withNoCustomerOnTheOrder_throws(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->builder->buildCustomerDto($order);
    }

    public function testBuildCustomerDto_withNoCustomerEmail_throws(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn(null);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $this->expectException(\LogicException::class);

        $this->builder->buildCustomerDto($order);
    }

    public function testBuildCustomerDto_withCustomerAndEmail_returnsCustomerDto(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn('customer@example.com');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $dto = $this->builder->buildCustomerDto($order);

        self::assertSame('7', $dto->id);
        self::assertSame('customer@example.com', $dto->email);
    }

    public function testBuildBrowserDto_withNoCurrentRequest_returnsNull(): void
    {
        self::assertNull($this->builder->buildBrowserDto());
    }

    public function testBuildBrowserDto_withACurrentRequest_returnsItsClientDetails(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->headers->set('referer', 'https://shop.test/checkout');
        $request->headers->set('User-Agent', 'TestAgent/1.0');
        $this->requestStack->push($request);

        $dto = $this->builder->buildBrowserDto();

        self::assertNotNull($dto);
        self::assertSame('203.0.113.5', $dto->ip);
        self::assertSame('https://shop.test/checkout', $dto->referrer);
        self::assertSame('TestAgent/1.0', $dto->userAgent);
    }

    public function testBuildCommonFields_setsSuccessCancelAndNotificationUrls(): void
    {
        $this->urlGenerator->method('generate')->willReturn('https://shop.test/payplug/notify/abc');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('00000042');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $common = $this->builder->buildCommonFields('acct_123', 1000, 'eur', 'sub_ext_1', $paymentRequest, $order);

        self::assertSame('acct_123', $common->accountId);
        self::assertSame(1000, $common->amount);
        self::assertSame('EUR', $common->currency);
        self::assertSame('00000042', $common->orderId);
        self::assertSame('sub_ext_1', $common->submerchantExternalId);
        self::assertSame('https://shop.test/payplug/notify/abc', $common->notificationUrl);
        self::assertSame('https://shop.test/order/00000042/pay', $common->successUrl);
        self::assertSame('https://shop.test/order/00000042/pay?status=canceled', $common->cancelUrl);
    }

    public function testBuildCommonFields_withNoOrder_fallsBackToPaymentIdAsOrderId(): void
    {
        $this->urlGenerator->method('generate')->willReturn('https://shop.test/payplug/notify/abc');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $common = $this->builder->buildCommonFields('acct_123', 1000, 'eur', 'sub_ext_1', $paymentRequest, null);

        self::assertSame('42', $common->orderId);
        self::assertNull($common->billing);
        self::assertNull($common->shipping);
    }

    public function testBuildCommonFields_withAnOrderItem_usesItsProductNameAsDescription(): void
    {
        $this->urlGenerator->method('generate')->willReturn('https://shop.test/payplug/notify/abc');

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getProductName')->willReturn('Blue T-Shirt');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('00000042');
        $order->method('getItems')->willReturn(new ArrayCollection([$item]));

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $common = $this->builder->buildCommonFields('acct_123', 1000, 'eur', 'sub_ext_1', $paymentRequest, $order);

        self::assertSame('Blue T-Shirt', $common->description);
    }

    public function testBuildCommonFields_withNoOrderItem_fallsBackToTheIntegrationDescription(): void
    {
        $this->urlGenerator->method('generate')->willReturn('https://shop.test/payplug/notify/abc');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('00000042');
        $order->method('getItems')->willReturn(new ArrayCollection());

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $common = $this->builder->buildCommonFields('acct_123', 1000, 'eur', 'sub_ext_1', $paymentRequest, $order);

        self::assertNotNull($common->description);
        self::assertNotSame('Blue T-Shirt', $common->description);
    }

    public function testResolveFullNameForCardDetails_withABillingAddressFullName_returnsIt(): void
    {
        $billingAddress = $this->createMock(AddressInterface::class);
        $billingAddress->method('getFullName')->willReturn('Jane Doe');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getBillingAddress')->willReturn($billingAddress);

        self::assertSame('Jane Doe', $this->builder->resolveFullNameForCardDetails($order));
    }

    public function testResolveFullNameForCardDetails_withNoBillingAddressFullName_fallsBackToTheCustomerFullName(): void
    {
        $billingAddress = $this->createMock(AddressInterface::class);
        $billingAddress->method('getFullName')->willReturn('');
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFullName')->willReturn('Jane Customer');
        $order = $this->createMock(OrderInterface::class);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getCustomer')->willReturn($customer);

        self::assertSame('Jane Customer', $this->builder->resolveFullNameForCardDetails($order));
    }

    public function testResolveFullNameForCardDetails_withNoNameAvailableAnywhere_returnsNull(): void
    {
        $order = $this->createMock(OrderInterface::class);

        self::assertNull($this->builder->resolveFullNameForCardDetails($order));
    }
}
