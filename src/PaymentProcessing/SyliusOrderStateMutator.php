<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SyliusOrderStateMutator implements IOrderStateMutator
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(string $orderId, string $outcome): void
    {
        $order = $this->orderRepository->find((int) $orderId);

        if (!$order instanceof OrderInterface) {
            throw new \LogicException(sprintf('No Sylius order found for id "%s".', $orderId));
        }

        $payment = $order->getLastPayment();

        if (null === $payment) {
            throw new \LogicException(sprintf('Order "%s" has no payment to apply outcome "%s" to.', $orderId, $outcome));
        }

        $transition = $this->mapOutcomeToTransition($outcome);

        if (null === $transition) {
            $this->logger->debug('[PayPlug] Outcome does not map to a Sylius payment transition yet.', [
                'order_id' => $orderId,
                'outcome' => $outcome,
            ]);

            return;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->logger->warning('[PayPlug] Cannot apply payment transition (already applied or incompatible with current state).', [
                'order_id' => $orderId,
                'payment_id' => $payment->getId(),
                'current_state' => $payment->getState(),
                'transition' => $transition,
                'outcome' => $outcome,
            ]);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
    }

    private function mapOutcomeToTransition(string $outcome): ?string
    {
        return match ($outcome) {
            PaymentOutcome::PAID => PaymentTransitions::TRANSITION_COMPLETE,
            PaymentOutcome::AUTHORIZED => PaymentTransitions::TRANSITION_AUTHORIZE,
            PaymentOutcome::REFUNDED => PaymentTransitions::TRANSITION_REFUND,
            PaymentOutcome::FAILED => PaymentTransitions::TRANSITION_FAIL,
            default => null, // CAPTURE_REQUIRED, THREE_DS_PENDING: no Sylius transition yet
        };
    }
}
