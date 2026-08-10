<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsPaymentProcessorInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Resource\Model\ResourceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

final class PostPaymentSelectEventSubscriber implements EventSubscriberInterface
{
    private const CHECKOUT_ROUTE = 'sylius_shop_checkout_select_payment';

    private const UPDATE_ORDER_PAYMENT_ROUTE = 'sylius_shop_order_show';

    private const TOKEN_FIELD = 'payplug_integrated_payment_token';

    private const HOSTED_FIELDS_TOKEN_FIELD = 'hostedfields_token';

    private const HOSTED_FIELDS_SELECTED_BRAND_FIELD = 'hostedfields_selected_brand';

    private const HOSTED_FIELDS_SAVE_CARD_FIELD = 'hostedfields_save_card';

    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private StateMachineInterface $stateMachine,
        private HostedFieldsPaymentProcessorInterface $hostedFieldsPaymentProcessor,
        private UrlGeneratorInterface $urlGenerator,
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
     * The target route differs per mode:
     * - Integrated Payment relays a real PayPlug `payment_id`, so the order goes to
     *   `sylius_shop_order_pay` (Payum capture/status) to be reconciled;
     * - Hosted Fields relays a Dalenys `hfToken` and has no `payment_id` yet (see
     *   NullHostedFieldsPaymentProcessor, pending PRE-3551). Reaching `sylius_shop_order_pay`
     *   would make StatusAction `markNew()`, Payum rebuild the details through Convert and
     *   CaptureAction issue a real createPayment() API call. It is sent to `sylius_shop_order_show`
     *   instead: same token-based, guest-friendly access, but Payum is never invoked.
     *
     * The Hosted Fields check comes first, mirroring handle()'s dispatch order: a request carrying
     * both token fields is processed as Hosted Fields, so it must be routed as Hosted Fields too.
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
            'route' => $this->hasHostedFieldsToken($request) ? self::UPDATE_ORDER_PAYMENT_ROUTE : 'sylius_shop_order_pay',
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

        if (!\in_array($request->attributes->get('_route'), [self::CHECKOUT_ROUTE, self::UPDATE_ORDER_PAYMENT_ROUTE], true)) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\OrderInterface $order */
        $order = $resourceControllerEvent->getSubject();
        $lastPayment = $order->getLastPayment();
        if (null === $lastPayment) {
            return;
        }

        if ($this->hasHostedFieldsToken($request)) {
            $this->handleHostedFieldsToken($request, $lastPayment, $resourceControllerEvent);

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

    private function handleHostedFieldsToken(
        Request $request,
        PaymentInterface $lastPayment,
        ResourceControllerEvent $resourceControllerEvent,
    ): void {
        // Guard against a crafted POST completing checkout through this path for a payment
        // method that does not actually have Hosted Fields enabled.
        if (!$this->isHostedFieldsEnabled($lastPayment)) {
            return;
        }

        $hfToken = $this->getRequestField($request, self::HOSTED_FIELDS_TOKEN_FIELD);
        $selectedBrand = $this->getRequestField($request, self::HOSTED_FIELDS_SELECTED_BRAND_FIELD);
        $saveCard = 'true' === $request->request->get(self::HOSTED_FIELDS_SAVE_CARD_FIELD, 'false');

        $this->hostedFieldsPaymentProcessor->process($lastPayment, $hfToken, $selectedBrand, $saveCard);

        $details = $lastPayment->getDetails();

        if (isset($details['error'])) {
            $this->requestStack->getSession()->getFlashBag()->add('error', 'payplug_sylius_payplug_plugin.error.uhf_payment_failed'); // @phpstan-ignore-line
            $resourceControllerEvent->setResponse(new RedirectResponse(
                $this->urlGenerator->generate(self::CHECKOUT_ROUTE),
            ));

            return;
        }

        $this->applyToComplete($lastPayment->getOrder() ?? throw new \LogicException('Order not found for payment'));

        $redirectUrl = $details['redirect_url'] ?? null;
        if (\is_string($redirectUrl) && '' !== $redirectUrl) {
            $resourceControllerEvent->setResponse(new RedirectResponse($redirectUrl));
        }
    }

    private function isHostedFieldsEnabled(PaymentInterface $payment): bool
    {
        return UhfGatewayFactory::FACTORY_NAME === $payment->getMethod()?->getGatewayConfig()?->getFactoryName();
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
