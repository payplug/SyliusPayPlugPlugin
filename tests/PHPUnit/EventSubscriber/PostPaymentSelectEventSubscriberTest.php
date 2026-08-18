<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\EventSubscriber\PostPaymentSelectEventSubscriber;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsPaymentProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PostPaymentSelectEventSubscriberTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private EntityManagerInterface&MockObject $entityManager;

    private StateMachineInterface&MockObject $stateMachine;

    private HostedFieldsPaymentProcessorInterface&MockObject $hostedFieldsPaymentProcessor;

    private PostPaymentSelectEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->hostedFieldsPaymentProcessor = $this->createMock(HostedFieldsPaymentProcessorInterface::class);

        $this->subscriber = new PostPaymentSelectEventSubscriber(
            $this->requestStack,
            $this->entityManager,
            $this->stateMachine,
            $this->hostedFieldsPaymentProcessor,
        );
    }

    public function testHandle_withHostedFieldsToken_delegatesToProcessorAndCompletesCheckout(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [PayPlugGatewayFactory::HOSTED_FIELDS => true]),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::once())
            ->method('process')
            ->with($payment, 'hf_token_abc', 'VISA', true)
        ;

        $this->stateMachine->method('can')
            ->with($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE)
            ->willReturn(true)
        ;
        $this->stateMachine->expects(self::once())->method('apply');
        $this->entityManager->expects(self::once())->method('flush');

        $this->subscriber->handle($event);
    }

    /**
     * A crafted POST carrying a hosted fields token must not be able to complete checkout
     * for a payment method that does not have Hosted Fields enabled.
     */
    public function testHandle_withHostedFieldsTokenButHostedFieldsNotEnabled_doesNotProcessNorCompleteCheckout(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [PayPlugGatewayFactory::HOSTED_FIELDS => false]),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->subscriber->handle($event);
    }

    /**
     * A crafted POST carrying a hosted fields token must not be able to complete checkout
     * for a payment method on a different gateway entirely, even if that gateway's config
     * coincidentally has a truthy value under the same HOSTED_FIELDS key. The factory-name
     * check must still gate first.
     */
    public function testHandle_withHostedFieldsTokenButDifferentFactory_doesNotProcessNorCompleteCheckout(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod('offline', [PayPlugGatewayFactory::HOSTED_FIELDS => true]),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->subscriber->handle($event);
    }

    /**
     * A payplug payment method whose config predates the Hosted Fields flag (key absent
     * entirely, e.g. a legacy config) must not be treated as Hosted-Fields-enabled. Pins
     * the `?? false` default explicitly, distinct from an explicit `false` value.
     */
    public function testHandle_withHostedFieldsTokenButConfigKeyAbsent_doesNotProcessNorCompleteCheckout(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, []),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->subscriber->handle($event);
    }

    /**
     * Pins the dispatch precedence that alterRequestConfigurationForInlineCardCapture() mirrors:
     * when both token fields are present, handle() treats the request as Hosted Fields (no
     * payment_id is ever written). Flipping this order without flipping the redirect ternary would
     * send a payment_id-less order to sylius_shop_order_pay.
     */
    public function testHandle_withBothTokens_isProcessedAsHostedFields(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'payplug_integrated_payment_token' => 'pay_123',
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'CB',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [PayPlugGatewayFactory::HOSTED_FIELDS => true]),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        // Hosted Fields path: the processor is used and no payment_id is written to the details.
        $this->hostedFieldsPaymentProcessor->expects(self::once())
            ->method('process')
            ->with($payment, 'hf_token_abc', 'CB', false)
        ;
        $payment->expects(self::never())->method('setDetails');

        $this->subscriber->handle($event);
    }

    public function testHandle_withHostedFieldsTokenButNoPaymentMethod_doesNotProcess(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(null);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->entityManager->expects(self::never())->method('flush');

        $this->subscriber->handle($event);
    }

    public function testHandle_withoutAnyToken_doesNothing(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST');
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->entityManager->expects(self::never())->method('flush');

        $this->subscriber->handle($event);
    }

    // -------------------------------------------------------------------------
    // alterRequestConfigurationForInlineCardCapture()
    // -------------------------------------------------------------------------

    /**
     * Integrated Payment relays a real PayPlug payment_id, so the redirect override to
     * `sylius_shop_order_pay` (Payum capture/status) must stay in place.
     */
    public function testAlterRequestConfiguration_withIntegratedPaymentToken_overridesRedirect(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'payplug_integrated_payment_token' => 'pay_123',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $request->attributes->set('_sylius', ['redirect' => ['route' => 'sylius_shop_checkout_complete']]);

        $this->subscriber->alterRequestConfigurationForInlineCardCapture($this->buildRequestEvent($request));

        self::assertSame(
            [
                'redirect' => [
                    'route' => 'sylius_shop_order_pay',
                    'parameters' => ['tokenValue' => 'resource.tokenValue'],
                ],
            ],
            $request->attributes->get('_sylius'),
        );
    }

    /**
     * Both Integrated Payment and Hosted Fields target `sylius_shop_order_pay` (Payum
     * capture/status for Integrated Payment; for Hosted Fields, the same `payplug`-tagged
     * Capture/Notify/StatusPaymentRequestCommandProvider trio delegates to their
     * Hosted-Fields-specific counterparts — see PayPlugGatewayFactory::isHostedFieldsConfig() —
     * so the payment is actually created/confirmed through UPC.
     */
    public function testAlterRequestConfigurationForInlineCardCapture_forHostedFieldsToken_redirectsToOrderPay(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $request->attributes->set('_sylius', ['redirect' => ['route' => 'sylius_shop_checkout_complete']]);

        $this->subscriber->alterRequestConfigurationForInlineCardCapture($this->buildRequestEvent($request));

        self::assertSame(
            [
                'redirect' => [
                    'route' => 'sylius_shop_order_pay',
                    'parameters' => ['tokenValue' => 'resource.tokenValue'],
                ],
            ],
            $request->attributes->get('_sylius'),
        );
    }

    /**
     * A crafted request carrying both token fields is dispatched as Hosted Fields by handle()
     * (it checks hasHostedFieldsToken() first). Since PRE-3551 both paths redirect to the same
     * route, so this just pins that the redirect override still applies regardless of which
     * token(s) are present.
     */
    public function testAlterRequestConfiguration_withBothTokens_followsHandleAndRedirectsToOrderPay(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'payplug_integrated_payment_token' => 'pay_123',
            'hostedfields_token' => 'hf_token_abc',
        ]);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $request->attributes->set('_sylius', []);

        $this->subscriber->alterRequestConfigurationForInlineCardCapture($this->buildRequestEvent($request));

        $syliusRequestConfig = $request->attributes->get('_sylius');
        self::assertSame('sylius_shop_order_pay', $syliusRequestConfig['redirect']['route']);
    }

    public function testAlterRequestConfiguration_withoutAnyToken_leavesRedirectUntouched(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST');
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        $syliusRequestConfig = ['redirect' => ['route' => 'sylius_shop_checkout_complete']];
        $request->attributes->set('_sylius', $syliusRequestConfig);

        $this->subscriber->alterRequestConfigurationForInlineCardCapture($this->buildRequestEvent($request));

        self::assertSame($syliusRequestConfig, $request->attributes->get('_sylius'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function buildPaymentMethod(string $factoryName, array $config = []): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }
}
