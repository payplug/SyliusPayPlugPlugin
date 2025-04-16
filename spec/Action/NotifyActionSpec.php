<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\Action\NotifyAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Notify;
use PhpSpec\ObjectBehavior;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Payment as SyliusPayment;

final class NotifyActionSpec extends ObjectBehavior
{
    public function let(
        LoggerInterface $logger,
        PaymentNotificationHandler $paymentNotificationHandler,
        RefundNotificationHandler $refundNotificationHandler,
    ): void {
        $this->beConstructedWith(
            $logger,
            $paymentNotificationHandler,
            $refundNotificationHandler,
        );
    }

    public function it_is_initializable(): void
    {
        $this->shouldHaveType(NotifyAction::class);
    }

    public function it_implements_action_interface(): void
    {
        $this->shouldHaveType(ActionInterface::class);
    }

    public function it_implements_api_aware_interface(): void
    {
        $this->shouldHaveType(ApiAwareInterface::class);
    }

    public function it_implements_gateway_aware_interface(): void
    {
        $this->shouldHaveType(GatewayAwareInterface::class);
    }

    public function it_executes(
        Notify $request,
        \ArrayObject $arrayObject,
        GatewayInterface $gateway,
        PayPlugApiClient $payPlugApiClient,
    ): void {
        $payment = \Mockery::mock('payment', Payment::class);

        $payment->is_paid = true;

        $this->setGateway($gateway);
        $this->setApi($payPlugApiClient);

        $request->getModel()->willReturn($arrayObject);
        $request->getFirstModel()->willReturn(new SyliusPayment());
        $payPlugApiClient->treat('')->willReturn($payment);

        $this->execute($request);
    }

    public function it_supports_only_notify_request_and_array_access(
        Notify $request,
        \ArrayAccess $arrayAccess,
    ): void {
        $request->getModel()->willReturn($arrayAccess);
        $this->supports($request)->shouldReturn(true);
    }
}
