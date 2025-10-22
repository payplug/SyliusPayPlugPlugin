<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
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

    private const UPDATE_ORDER_PAYMENT_ROUTE = 'sylius_shop_order_show';

    private const TOKEN_FIELD = 'payplug_integrated_payment_token';

    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'alterRequestConfigurationForIntegratedPayment',
            'sylius.order.post_payment' => 'handle',
            'sylius.order.post_update' => 'handle',
        ];
    }

    public function alterRequestConfigurationForIntegratedPayment(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->hasToken($request) || self::CHECKOUT_ROUTE !== $request->attributes->get('_route')) {
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

        if (!\in_array($request->attributes->get('_route'), [self::CHECKOUT_ROUTE, self::UPDATE_ORDER_PAYMENT_ROUTE], true)) {
            return;
        }

        /** @var \Sylius\Component\Core\Model\OrderInterface $order */
        $order = $resourceControllerEvent->getSubject();
        $lastPayment = $order->getLastPayment();
        if (null === $lastPayment) {
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

    private function applyToComplete(OrderInterface $order): void
    {
        if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        }

        $this->entityManager->flush();
    }
}
