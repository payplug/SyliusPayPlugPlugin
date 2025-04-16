<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use PayPlug\SyliusPayPlugPlugin\Action\StatusAction;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\GetStatusInterface;
use PhpSpec\ObjectBehavior;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class StatusActionSpec extends ObjectBehavior
{
    public function let(
        FactoryInterface $stateMachineFactory,
        RefundPaymentHandlerInterface $refundPaymentHandler,
        PaymentNotificationHandler $paymentNotificationHandler,
        RequestStack $requestStack,
    ): void {
        $this->beConstructedWith($stateMachineFactory, $refundPaymentHandler, $paymentNotificationHandler, $requestStack);
    }

    public function it_is_initializable(): void
    {
        $this->shouldHaveType(StatusAction::class);
    }

    public function it_implements_action_interface(): void
    {
        $this->shouldHaveType(ActionInterface::class);
    }

    public function it_implements_gateway_aware_interface(): void
    {
        $this->shouldHaveType(GatewayAwareInterface::class);
    }

    public function it_executes(
        GetStatusInterface $request,
        PaymentInterface $payment,
        GatewayInterface $gateway,
    ): void {
        $this->setGateway($gateway);

        $payment->getDetails()->willReturn([]);
        $request->getModel()->willReturn($payment);

        $request->markNew()->shouldBeCalled();

        $this->execute($request);
    }

    public function it_supports_only_get_status_request_and_array_access(
        GetStatusInterface $request,
        PaymentInterface $payment,
    ): void {
        $request->getModel()->willReturn($payment);
        $this->supports($request)->shouldReturn(true);
    }
}
