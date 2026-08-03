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
            $this->buildPaymentMethod([PayPlugGatewayFactory::HOSTED_FIELDS => true]),
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
     * for a payment method that does not have the Hosted Fields flag enabled.
     */
    public function testHandle_withHostedFieldsTokenButFlagDisabled_doesNotProcessNorCompleteCheckout(): void
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
            $this->buildPaymentMethod([PayPlugGatewayFactory::HOSTED_FIELDS => false]),
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
     * Hosted Fields has no PayPlug payment_id yet (PRE-3551): reaching `sylius_shop_order_pay` would
     * make StatusAction markNew() and end up issuing a real createPayment() API call. A `redirect`
     * entry is still required (Sylius's CheckoutRedirectListener would otherwise fail to resolve a
     * route for the `completed` checkout state), so it points at `sylius_shop_order_show` instead.
     */
    public function testAlterRequestConfiguration_withOnlyHostedFieldsToken_redirectsToOrderShowNotOrderPay(): void
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
                    'route' => 'sylius_shop_order_show',
                    'parameters' => ['tokenValue' => 'resource.tokenValue'],
                ],
            ],
            $request->attributes->get('_sylius'),
        );
    }

    /**
     * Belt and braces: the Hosted Fields branch must never resolve to the Payum-invoking route.
     */
    public function testAlterRequestConfiguration_withBothTokens_prefersIntegratedPaymentRoute(): void
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

    private function buildPaymentMethod(array $config): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn($config);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }
}
