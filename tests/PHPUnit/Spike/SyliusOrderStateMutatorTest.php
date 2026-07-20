<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Spike;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusOrderStateMutator;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SyliusOrderStateMutatorTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    private StateMachineInterface&MockObject $stateMachine;

    private EntityManagerInterface&MockObject $entityManager;

    private SyliusOrderStateMutator $mutator;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->mutator = new SyliusOrderStateMutator($this->orderRepository, $this->stateMachine, $this->entityManager);
    }

    /**
     * THREE_DS_PENDING must stay a no-op — the order remains `new` until the async webhook
     * resolves it. Neither the order repository nor the state machine should even be touched.
     */
    public function testApply_threeDsPending_isNoOp(): void
    {
        $this->orderRepository->expects(self::never())->method('findOrderById');
        $this->stateMachine->expects(self::never())->method('apply');

        $this->mutator->apply('order-1', PaymentOutcome::THREE_DS_PENDING);
    }

    /**
     * @dataProvider provideMappedOutcomes
     */
    public function testApply_mappedOutcome_appliesExpectedTransition(string $outcome, string $expectedTransition): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $this->orderRepository->method('findOrderById')->with('order-1')->willReturn($order);
        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, $expectedTransition)->willReturn(true);
        $this->stateMachine->expects(self::once())->method('apply')->with($payment, PaymentTransitions::GRAPH, $expectedTransition);
        $this->entityManager->expects(self::once())->method('flush');

        $this->mutator->apply('order-1', $outcome);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideMappedOutcomes(): array
    {
        return [
            'paid' => [PaymentOutcome::PAID, PaymentTransitions::TRANSITION_COMPLETE],
            'authorized' => [PaymentOutcome::AUTHORIZED, PaymentTransitions::TRANSITION_AUTHORIZE],
            'capture_required' => [PaymentOutcome::CAPTURE_REQUIRED, PaymentTransitions::TRANSITION_PROCESS],
            'refunded' => [PaymentOutcome::REFUNDED, PaymentTransitions::TRANSITION_REFUND],
            'failed' => [PaymentOutcome::FAILED, PaymentTransitions::TRANSITION_FAIL],
        ];
    }

    /**
     * A retried webhook landing on a payment already past this transition is a silent no-op,
     * not a thrown workflow exception — mirrors the plugin's existing PaymentStateResolver.
     */
    public function testApply_transitionNotAvailable_isSilentNoOp(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $order->method('getLastPayment')->willReturn($payment);

        $this->orderRepository->method('findOrderById')->willReturn($order);
        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::never())->method('apply');
        $this->entityManager->expects(self::never())->method('flush');

        $this->mutator->apply('order-1', PaymentOutcome::PAID);
    }

    public function testApply_orderNotFound_throwsPaymentNotFoundException(): void
    {
        $this->orderRepository->method('findOrderById')->with('missing-order')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->mutator->apply('missing-order', PaymentOutcome::PAID);
    }

    public function testApply_orderHasNoPayment_throwsPaymentNotFoundException(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getLastPayment')->willReturn(null);
        $this->orderRepository->method('findOrderById')->willReturn($order);

        $this->expectException(PaymentNotFoundException::class);

        $this->mutator->apply('order-1', PaymentOutcome::PAID);
    }

    public function testApply_unmappedOutcome_throwsInvalidArgumentException(): void
    {
        $this->orderRepository->expects(self::never())->method('findOrderById');

        $this->expectException(\InvalidArgumentException::class);

        $this->mutator->apply('order-1', 'not_a_real_outcome');
    }
}
