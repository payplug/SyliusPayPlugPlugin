<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

class PaymentTransitionApplier
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(PaymentInterface $payment): bool
    {
        $details = $payment->getDetails(); // @phpstan-ignore-line - getDetails() return mixed
        $status = $details['status'] ?? '';

        // These are known PayPlug statuses that do not map to a Sylius payment transition.
        if (\in_array($status, [
            PayPlugApiClientInterface::STATUS_CREATED,
            PayPlugApiClientInterface::REFUNDED,
            PayPlugApiClientInterface::INTERNAL_STATUS_ONE_CLICK,
        ], true)) {
            return false;
        }

        $transition = match ($status) {
            PayPlugApiClientInterface::STATUS_ABORTED, PayPlugApiClientInterface::STATUS_CANCELED, PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY => PaymentTransitions::TRANSITION_CANCEL,
            PayPlugApiClientInterface::STATUS_AUTHORIZED => PaymentTransitions::TRANSITION_AUTHORIZE,
            PayPlugApiClientInterface::STATUS_CAPTURED => PaymentTransitions::TRANSITION_COMPLETE,
            PayPlugApiClientInterface::FAILED => PaymentTransitions::TRANSITION_FAIL,
            default => null,
        };

        if (null === $transition) {
            $this->logger->warning('[PayPlug] Cannot apply payment transition: unknown status.', [
                'sylius_payment_id' => $payment->getId(),
                'payplug_payment_id' => $details['payment_id'] ?? null,
                'status' => $status,
            ]);

            return false;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->logger->warning('[PayPlug] Cannot apply payment transition (already applied or incompatible with current state).', [
                'sylius_payment_id' => $payment->getId(),
                'payplug_payment_id' => $details['payment_id'] ?? null,
                'current_state' => $payment->getState(),
                'transition' => $transition,
                'status' => $status,
            ]);

            return false;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);

        return true;
    }
}
