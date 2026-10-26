<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\EventSubscriber\PostPaymentSelectEventSubscriber;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsCaptureData;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsCardDetails;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsPaymentProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PostPaymentSelectEventSubscriberTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private EntityManagerInterface&MockObject $entityManager;

    private StateMachineInterface&MockObject $stateMachine;

    private HostedFieldsPaymentProcessorInterface&MockObject $hostedFieldsPaymentProcessor;

    private LoggerInterface&MockObject $logger;

    private PostPaymentSelectEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->hostedFieldsPaymentProcessor = $this->createMock(HostedFieldsPaymentProcessorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new PostPaymentSelectEventSubscriber(
            $this->requestStack,
            $this->entityManager,
            $this->stateMachine,
            $this->hostedFieldsPaymentProcessor,
            $this->logger,
        );
    }

    public function testHandle_withHostedFieldsToken_delegatesToProcessorAndCompletesCheckout(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
            'hostedfields_card_holder_name' => '  John Doe ',
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
            ->with($payment, new HostedFieldsCaptureData('hf_token_abc', 'VISA', true, new HostedFieldsCardDetails('4242', 12, 2030, 'FR'), 'John Doe'))
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
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
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
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
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
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
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
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
            'hostedfields_card_holder_name' => 'John Doe',
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
            ->with($payment, new HostedFieldsCaptureData('hf_token_abc', 'CB', false, new HostedFieldsCardDetails('4242', 12, 2030, 'FR'), 'John Doe'))
        ;
        $payment->expects(self::never())->method('setDetails');

        $this->subscriber->handle($event);
    }

    /**
     * @dataProvider provideInvalidSubmissions
     */
    public function testHandle_withInvalidHostedFieldsSubmission_redirectsBackWithAFlashAndRecordsNothing(
        array $fields,
        string $rejectedValue,
    ): void
    {
        $event = $this->buildHostedFieldsCheckoutEvent($fields, hostedFieldsEnabled: true, payment: $payment, request: $request);

        $payment->expects(self::never())->method('setDetails');
        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');
        $this->logger->expects(self::once())->method('warning')
            ->with(
                self::isType('string'),
                self::callback(static fn (array $context): bool => !\str_contains(\json_encode($context, \JSON_THROW_ON_ERROR), $rejectedValue)),
            );
        $event->expects(self::once())->method('setResponse')
            ->with(self::callback(static fn (Response $response): bool => $response instanceof RedirectResponse &&
                '/checkout/select-payment' === $response->getTargetUrl()))
        ;

        $this->subscriber->handle($event);

        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame(['payplug_sylius_payplug_plugin.error.hosted_fields_submission_invalid'], $session->getFlashBag()->get('error'));
    }

    /**
     * Without a session (stateless request), the shopper is still sent back to the payment step.
     */
    public function testHandle_withInvalidHostedFieldsSubmissionAndNoSession_stillRedirectsBack(): void
    {
        $event = $this->buildHostedFieldsCheckoutEvent(['hostedfields_card_holder_name' => null], hostedFieldsEnabled: true, withSession: false);

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $event->expects(self::once())->method('setResponse')->with(self::isInstanceOf(RedirectResponse::class));

        $this->subscriber->handle($event);
    }

    /**
     * ResourceController::updateAction() answers a non-HTML request with its REST view before it
     * reads the event response, so a headless client is told with a 400 instead of a redirect.
     */
    public function testHandle_withInvalidHostedFieldsSubmissionInJson_throwsBadRequestWithoutFlash(): void
    {
        $event = $this->buildHostedFieldsCheckoutEvent(['hostedfields_card_holder_name' => null], hostedFieldsEnabled: true, request: $request);
        $request->setRequestFormat('json');

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');
        $event->expects(self::never())->method('setResponse');

        try {
            $this->subscriber->handle($event);
            self::fail('A refused submission in a non-HTML request must answer 400.');
        } catch (BadRequestHttpException) {
        }

        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame([], $session->getFlashBag()->peekAll());
    }

    /**
     * The brand is normalised end to end: the browser may post it padded or in lower case.
     */
    public function testHandle_withPaddedLowercaseBrand_processesTheNormalisedBrand(): void
    {
        $event = $this->buildHostedFieldsCheckoutEvent(['hostedfields_selected_brand' => ' visa '], hostedFieldsEnabled: true);

        $this->hostedFieldsPaymentProcessor->expects(self::once())->method('process')
            ->with(self::anything(), self::callback(static fn (HostedFieldsCaptureData $data): bool => 'VISA' === $data->selectedBrand))
        ;
        $event->expects(self::never())->method('setResponse');

        $this->subscriber->handle($event);
    }

    public static function provideInvalidSubmissions(): iterable
    {
        yield 'missing cardholder name' => [['hostedfields_card_holder_name' => null], 'hf_token_abc'];
        yield 'cardholder name too short' => [['hostedfields_card_holder_name' => 'Jo B'], 'Jo B'];
        yield 'cardholder name too long' => [['hostedfields_card_holder_name' => \str_repeat('x', 46)], \str_repeat('x', 46)];
        yield 'cardholder name not valid UTF-8' => [['hostedfields_card_holder_name' => "John \xFF Doe"], "John \xFF Doe"];
        yield 'unknown brand' => [['hostedfields_selected_brand' => 'AMEX'], 'AMEX'];
        yield 'missing brand' => [['hostedfields_selected_brand' => null], 'John Doe'];
        yield 'cardholder name with a control character' => [['hostedfields_card_holder_name' => "John\nDoe"], "John\nDoe"];
    }

    /**
     * The Hosted Fields guard runs before the submission is validated: an invalid submission for a
     * payment method without Hosted Fields is ignored like any other Hosted Fields POST.
     */
    public function testHandle_withInvalidSubmissionAndHostedFieldsNotEnabled_doesNothing(): void
    {
        $event = $this->buildHostedFieldsCheckoutEvent(
            ['hostedfields_card_holder_name' => null, 'hostedfields_selected_brand' => 'AMEX'],
            hostedFieldsEnabled: false,
        );

        $this->hostedFieldsPaymentProcessor->expects(self::never())->method('process');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');
        $this->logger->expects(self::never())->method('warning');

        $this->subscriber->handle($event);
    }

    public function testHandle_withHostedFieldsTokenButNoPaymentMethod_doesNotProcess(): void
    {
        $request = Request::create('/checkout/select-payment', 'POST', [
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
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

    /**
     * A valid Hosted Fields POST, with $overrides applied on top (a null value removes the field).
     *
     * @param array<string, string|null> $overrides
     */
    private function buildHostedFieldsCheckoutEvent(
        array $overrides,
        bool $hostedFieldsEnabled,
        (PaymentInterface&MockObject)|null &$payment = null,
        ?Request &$request = null,
        bool $withSession = true,
    ): ResourceControllerEvent&MockObject {
        $fields = \array_filter(\array_merge([
            'hostedfields_token' => 'hf_token_abc',
            'hostedfields_selected_brand' => 'VISA',
            'hostedfields_save_card' => 'true',
            'hostedfields_last4' => '4242',
            'hostedfields_exp_month' => '12',
            'hostedfields_exp_year' => '2030',
            'hostedfields_country' => 'FR',
            'hostedfields_card_holder_name' => 'John Doe',
        ], $overrides), static fn (?string $value): bool => null !== $value);

        $request = Request::create('/checkout/select-payment', 'POST', $fields);
        $request->attributes->set('_route', 'sylius_shop_checkout_select_payment');
        if ($withSession) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(
            $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [PayPlugGatewayFactory::HOSTED_FIELDS => $hostedFieldsEnabled]),
        );
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $payment->method('getOrder')->willReturn($order);

        $event = $this->createMock(ResourceControllerEvent::class);
        $event->method('getSubject')->willReturn($order);

        return $event;
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
