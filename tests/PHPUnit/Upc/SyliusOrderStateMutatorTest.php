<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\SyliusOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SyliusOrderStateMutatorTest extends TestCase
{
    private PaymentRepositoryInterface&MockObject $paymentRepository;

    private StateMachineInterface&MockObject $stateMachine;

    private LoggerInterface&MockObject $logger;

    private SyliusOrderStateMutator $mutator;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mutator = new SyliusOrderStateMutator($this->paymentRepository, $this->stateMachine, $this->logger);
    }

    /**
     * @dataProvider outcomeToTransitionProvider
     */
    public function testApply_mapsOutcomeToTheExpectedTransition(string $outcome, string $expectedTransition): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $this->paymentRepository->method('find')->with(42)->willReturn($payment);
        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, $expectedTransition)->willReturn(true);
        $this->stateMachine->expects(self::once())->method('apply')->with($payment, PaymentTransitions::GRAPH, $expectedTransition);

        $this->mutator->apply('42', $outcome);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outcomeToTransitionProvider(): iterable
    {
        yield 'paid' => [PaymentOutcome::PAID, PaymentTransitions::TRANSITION_COMPLETE];
        yield 'capture_required' => [PaymentOutcome::CAPTURE_REQUIRED, PaymentTransitions::TRANSITION_COMPLETE];
        yield 'authorized' => [PaymentOutcome::AUTHORIZED, PaymentTransitions::TRANSITION_AUTHORIZE];
        yield 'refunded' => [PaymentOutcome::REFUNDED, PaymentTransitions::TRANSITION_REFUND];
        yield 'failed' => [PaymentOutcome::FAILED, PaymentTransitions::TRANSITION_FAIL];
    }

    public function testApply_forThreeDsPending_doesNothing(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $this->paymentRepository->method('find')->willReturn($payment);
        $this->stateMachine->expects(self::never())->method('apply');

        $this->mutator->apply('42', PaymentOutcome::THREE_DS_PENDING);
    }

    public function testApply_whenPaymentNotFound_logsAndDoesNothing(): void
    {
        $this->paymentRepository->method('find')->with(42)->willReturn(null);
        $this->logger->expects(self::once())->method('error');
        $this->stateMachine->expects(self::never())->method('apply');

        $this->mutator->apply('42', PaymentOutcome::PAID);
    }

    public function testApply_whenTransitionNotAllowed_logsAndDoesNotApply(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $this->paymentRepository->method('find')->willReturn($payment);
        $this->stateMachine->method('can')->willReturn(false);
        $this->logger->expects(self::once())->method('warning');
        $this->stateMachine->expects(self::never())->method('apply');

        $this->mutator->apply('42', PaymentOutcome::PAID);
    }
}
