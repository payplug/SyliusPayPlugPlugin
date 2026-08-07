<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\SyliusOrderStateMutator;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SyliusOrderStateMutatorTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    private StateMachineInterface&MockObject $stateMachine;

    private LoggerInterface&MockObject $logger;

    private SyliusOrderStateMutator $mutator;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->mutator = new SyliusOrderStateMutator($this->orderRepository, $this->stateMachine, $this->logger);
    }

    public function testApply_withPaidOutcome_appliesCompleteTransitionToTheLastPayment(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $this->orderRepository->method('find')->with(42)->willReturn($order);

        $this->stateMachine->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)
            ->willReturn(true)
        ;
        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)
        ;

        $this->mutator->apply('42', PaymentOutcome::PAID);
    }

    public function testApply_withFailedOutcome_appliesFailTransition(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $this->orderRepository->method('find')->willReturn($order);

        $this->stateMachine->method('can')->willReturn(true);
        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)
        ;

        $this->mutator->apply('42', PaymentOutcome::FAILED);
    }

    public function testApply_withThreeDsPendingOutcome_doesNotTransitionAndLogs(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $this->orderRepository->method('find')->willReturn($order);

        $this->stateMachine->expects(self::never())->method('apply');
        $this->logger->expects(self::once())->method('debug');

        $this->mutator->apply('42', PaymentOutcome::THREE_DS_PENDING);
    }

    public function testApply_whenTransitionNotAllowed_logsAWarningAndDoesNotThrow(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn($payment);
        $this->orderRepository->method('find')->willReturn($order);

        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::never())->method('apply');
        $this->logger->expects(self::once())->method('warning');

        $this->mutator->apply('42', PaymentOutcome::PAID);
    }

    public function testApply_whenOrderNotFound_throwsLogicException(): void
    {
        $this->orderRepository->method('find')->willReturn(null);
        $this->expectException(\LogicException::class);
        $this->mutator->apply('999', PaymentOutcome::PAID);
    }

    public function testApply_whenOrderHasNoPayment_throwsLogicException(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn(null);
        $this->orderRepository->method('find')->willReturn($order);

        $this->expectException(\LogicException::class);
        $this->mutator->apply('42', PaymentOutcome::PAID);
    }
}
