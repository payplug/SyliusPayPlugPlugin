<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\EventSubscriber\PostPaymentSelectEventSubscriber;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\HostedFieldsPaymentProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
}
