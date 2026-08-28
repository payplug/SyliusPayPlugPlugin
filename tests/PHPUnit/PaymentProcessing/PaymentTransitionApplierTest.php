<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentTransitionApplierTest extends TestCase
{
    private StateMachineInterface&MockObject $stateMachine;

    private LoggerInterface&MockObject $logger;

    private PaymentTransitionApplier $applier;

    protected function setUp(): void
    {
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->applier = new PaymentTransitionApplier($this->stateMachine, $this->logger);
    }

    // -------------------------------------------------------------------------
    // apply() — known status, transition possible → transition applied
    // -------------------------------------------------------------------------

    /**
     * The PayPlug status maps to a known transition and the state machine allows it.
     * Verifies apply() applies the transition and returns true.
     */
    public function testApply_withKnownStatusAndAllowedTransition_appliesTransitionAndReturnsTrue(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['status' => PayPlugApiClientInterface::STATUS_CAPTURED]);

        $this->stateMachine->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)
            ->willReturn(true)
        ;
        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)
        ;
        $this->logger->expects(self::never())->method('warning');

        self::assertTrue($this->applier->apply($payment));
    }

    // -------------------------------------------------------------------------
    // apply() — unknown status → no transition applied
    // -------------------------------------------------------------------------

    /**
     * The PayPlug status does not map to any known transition.
     * Verifies apply() logs a warning, never calls the state machine, and returns false.
     */
    public function testApply_withUnknownStatus_logsWarningAndReturnsFalse(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['status' => 'some_unhandled_status']);

        $this->stateMachine->expects(self::never())->method('can');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->logger->expects(self::once())
            ->method('warning')
            ->with('[PayPlug] Cannot apply payment transition: unknown status.', self::isType('array'))
        ;

        self::assertFalse($this->applier->apply($payment));
    }

    // -------------------------------------------------------------------------
    // apply() — known status, transition not allowed → no transition applied
    // -------------------------------------------------------------------------

    /**
     * The PayPlug status maps to a known transition but the state machine refuses it
     * (e.g. already applied, or incompatible with the payment's current state).
     * Verifies apply() logs a warning, never applies the transition, and returns false.
     */
    public function testApply_withKnownStatusButDisallowedTransition_logsWarningAndReturnsFalse(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['status' => PayPlugApiClientInterface::FAILED]);
        $payment->method('getState')->willReturn('completed');

        $this->stateMachine->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)
            ->willReturn(false)
        ;
        $this->stateMachine->expects(self::never())->method('apply');
        $this->logger->expects(self::once())
            ->method('warning')
            ->with('[PayPlug] Cannot apply payment transition (already applied or incompatible with current state).', self::isType('array'))
        ;

        self::assertFalse($this->applier->apply($payment));
    }

    // -------------------------------------------------------------------------
    // apply() — known-but-inapplicable status → silent no-op
    // -------------------------------------------------------------------------

    /**
     * These PayPlug statuses are known but do not map to a Sylius payment transition
     * (e.g. the payment session was just created, was refunded outside Sylius, or is
     * a failed one-click intermediate state already handled elsewhere). Verifies
     * apply() returns false without logging a warning or touching the state machine.
     *
     * @dataProvider noOpStatusesDataProvider
     */
    public function testApply_withKnownNoOpStatus_doesNothingAndReturnsFalse(string $status): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['status' => $status]);

        $this->stateMachine->expects(self::never())->method('can');
        $this->stateMachine->expects(self::never())->method('apply');
        $this->logger->expects(self::never())->method('warning');

        self::assertFalse($this->applier->apply($payment));
    }

    public function noOpStatusesDataProvider(): \Generator
    {
        yield 'created' => [PayPlugApiClientInterface::STATUS_CREATED];
        yield 'refunded' => [PayPlugApiClientInterface::REFUNDED];
        yield 'one_click' => [PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK];
    }
}
