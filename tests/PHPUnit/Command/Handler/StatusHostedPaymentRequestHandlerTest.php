<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\Handler\StatusHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

final class StatusHostedPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private StatusHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->handler = new StatusHostedPaymentRequestHandler($this->paymentRequestProvider, $this->stateMachine);
    }

    private function paymentRequest(): PaymentRequestInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    public function testInvoke_withNoForcedStatus_onlyCompletesThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest();

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withForcedCanceledStatus_cancelsThePaymentWhenAllowed(): void
    {
        $paymentRequest = $this->paymentRequest();
        $payment = $paymentRequest->getPayment();

        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->expects(self::exactly(2))->method('apply');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }

    public function testInvoke_withForcedCanceledStatus_whenTransitionNotAllowed_stillCompletesThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest();

        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }
}
