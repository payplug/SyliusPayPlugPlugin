<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsPaymentProcessorInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Resource\Model\ResourceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Webmozart\Assert\Assert;

final class PostPaymentSelectEventSubscriber implements EventSubscriberInterface
{
    private const CHECKOUT_ROUTE = 'sylius_shop_checkout_select_payment';

    private const TOKEN_FIELD = 'payplug_integrated_payment_token';

    private const HOSTED_FIELDS_TOKEN_FIELD = 'hostedfields_token';

    private const HOSTED_FIELDS_SELECTED_BRAND_FIELD = 'hostedfields_selected_brand';

    private const HOSTED_FIELDS_SAVE_CARD_FIELD = 'hostedfields_save_card';

    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private StateMachineInterface $stateMachine,
        private HostedFieldsPaymentProcessorInterface $hostedFieldsPaymentProcessor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'alterRequestConfigurationForInlineCardCapture',
            'sylius.order.post_payment' => 'handle',
            'sylius.order.post_update' => 'handle',
        ];
    }

    /**
     * Both inline card-capture modes force the checkout to TRANSITION_COMPLETE inside
     * `sylius.order.post_payment` (see handle()), so a `redirect` entry MUST be injected here:
     * Sylius's CheckoutRedirectListener listens to that same event and bails out only when
     * `_sylius['redirect']` is set. Without it, it would resolve a route for the `completed`
     * checkout state, which has no entry in `sylius_shop.checkout_resolver.route_map`
     * (RouteNotFoundException).
     *
     * Both Integrated Payment and Hosted Fields target `sylius_shop_order_pay` (Payum
     * capture/status for Integrated Payment; for Hosted Fields, the same `payplug`-tagged
     * Capture/Notify/StatusPaymentRequestCommandProvider trio delegates to their
     * Hosted-Fields-specific counterparts — see PayPlugGatewayFactory::isHostedFieldsConfig() —
     * so the payment is actually created/confirmed through UPC.
     */
    public function alterRequestConfigurationForInlineCardCapture(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (
            (!$this->hasToken($request) && !$this->hasHostedFieldsToken($request)) ||
            self::CHECKOUT_ROUTE !== $request->attributes->get('_route')
        ) {
            return;
        }
        if (!$request->attributes->has('_sylius')) {
            return;
        }

        $syliusRequestConfig = $request->attributes->get('_sylius');
        if (!\is_array($syliusRequestConfig)) {
            return;
        }

        $syliusRequestConfig['redirect'] = [
            'route' => 'sylius_shop_order_pay',
            'parameters' => ['tokenValue' => 'resource.tokenValue'],
        ];

        $request->attributes->set('_sylius', $syliusRequestConfig);
    }

    public function handle(ResourceControllerEvent $resourceControllerEvent): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return;
        }

        if (self::CHECKOUT_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\OrderInterface $order */
        $order = $resourceControllerEvent->getSubject();
        $lastPayment = $order->getLastPayment();
        if (null === $lastPayment) {
            return;
        }

        if ($this->hasHostedFieldsToken($request)) {
            $this->handleHostedFieldsToken($request, $lastPayment);

            return;
        }

        if (!$this->hasToken($request)) {
            return;
        }
        $this->handleToken($resourceControllerEvent, $request, $lastPayment);
    }

    private function handleToken(
        ResourceControllerEvent $resourceControllerEvent,
        Request $request,
        PaymentInterface $lastPayment,
    ): void {
        $token = $this->getToken($request);

        $lastPayment->setDetails(\array_merge(
            $lastPayment->getDetails(),
            [
                'payment_id' => $token,
                'status' => PaymentInterface::STATE_PROCESSING,
            ],
        ));

        $resource = $resourceControllerEvent->getSubject();
        Assert::isInstanceOf($resource, ResourceInterface::class);

        $this->applyToComplete($lastPayment->getOrder() ?? throw new \LogicException('Order not found for payment'));
    }

    private function hasToken(Request $request): bool
    {
        if (!$request->request->has(self::TOKEN_FIELD)) {
            return false;
        }

        $token = $this->getToken($request);

        return '' !== $token;
    }

    private function getToken(Request $request): string
    {
        $token = $request->request->get(self::TOKEN_FIELD);
        Assert::string($token);

        return $token;
    }

    private function hasHostedFieldsToken(Request $request): bool
    {
        if (!$request->request->has(self::HOSTED_FIELDS_TOKEN_FIELD)) {
            return false;
        }

        return '' !== $this->getRequestField($request, self::HOSTED_FIELDS_TOKEN_FIELD);
    }

    private function handleHostedFieldsToken(Request $request, PaymentInterface $lastPayment): void
    {
        // Guard against a crafted POST completing checkout through this path for a payment
        // method that does not actually have Hosted Fields enabled.
        if (!$this->isHostedFieldsEnabled($lastPayment)) {
            return;
        }

        $hfToken = $this->getRequestField($request, self::HOSTED_FIELDS_TOKEN_FIELD);
        $selectedBrand = $this->getRequestField($request, self::HOSTED_FIELDS_SELECTED_BRAND_FIELD);
        $saveCard = 'true' === $request->request->get(self::HOSTED_FIELDS_SAVE_CARD_FIELD, 'false');

        $this->hostedFieldsPaymentProcessor->process($lastPayment, $hfToken, $selectedBrand, $saveCard);

        $this->applyToComplete($lastPayment->getOrder() ?? throw new \LogicException('Order not found for payment'));
    }

    private function isHostedFieldsEnabled(PaymentInterface $payment): bool
    {
        return PayPlugGatewayFactory::isHostedFieldsConfig($payment->getMethod()?->getGatewayConfig());
    }

    private function getRequestField(Request $request, string $field): string
    {
        $value = $request->request->get($field, '');
        Assert::string($value);

        return $value;
    }

    private function applyToComplete(OrderInterface $order): void
    {
        if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        }

        $this->entityManager->flush();
    }
}
