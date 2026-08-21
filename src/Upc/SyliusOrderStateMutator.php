<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SyliusOrderStateMutator implements IOrderStateMutator
{
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(string $orderId, string $outcome): void
    {
        /** @var PaymentInterface|null $payment */
        $payment = $this->paymentRepository->find((int) $orderId);
        if (null === $payment) {
            $this->logger->error('[PayPlug][UPC] Cannot apply payment outcome: payment not found.', [
                'sylius_payment_id' => $orderId,
                'outcome' => $outcome,
            ]);

            return;
        }

        $transition = match ($outcome) {
            PaymentOutcome::PAID, PaymentOutcome::CAPTURE_REQUIRED => PaymentTransitions::TRANSITION_COMPLETE,
            PaymentOutcome::AUTHORIZED => PaymentTransitions::TRANSITION_AUTHORIZE,
            PaymentOutcome::REFUNDED => PaymentTransitions::TRANSITION_REFUND,
            PaymentOutcome::FAILED => PaymentTransitions::TRANSITION_FAIL,
            // THREE_DS_PENDING is not applied as a state transition here: it's not a final
            // outcome, so there is nothing to transition to yet — the payment simply stays
            // "processing" until a real outcome arrives. The three async notification call sites
            // (HostedFieldsWebhookNotificationHandler::treat(), NotifyHostedPaymentRequestHandler,
            // and StatusHostedPaymentRequestHandler via delegation to treat()) already guard
            // against THREE_DS_PENDING before ever reaching this mutator; this arm is the
            // defensive backstop for the one caller that doesn't (CaptureHostedPaymentRequestHandler's
            // synchronous branch, which passes ExecCodeMapper::toPaymentOutcome() straight through).
            default => null,
        };

        if (null === $transition) {
            return;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->logger->warning('[PayPlug][UPC] Cannot apply payment transition (already applied or incompatible with current state).', [
                'sylius_payment_id' => $orderId,
                'current_state' => $payment->getState(),
                'transition' => $transition,
                'outcome' => $outcome,
            ]);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
    }
}
