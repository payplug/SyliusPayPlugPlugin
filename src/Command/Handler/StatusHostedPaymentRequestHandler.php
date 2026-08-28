<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StatusHostedPaymentRequestHandler
{
    private const FORCED_STATUS_CANCELED = 'canceled';

    // States that already carry a final outcome — reached by the webhook, by the synchronous
    // branch in CaptureHostedPaymentRequestHandler, or by a previous poll. Anything else is still
    // awaiting an outcome and is worth polling for.
    private const RESOLVED_STATES = [
        PaymentInterface::STATE_COMPLETED,
        PaymentInterface::STATE_FAILED,
        PaymentInterface::STATE_CANCELLED,
        PaymentInterface::STATE_REFUNDED,
        PaymentInterface::STATE_AUTHORIZED,
    ];

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private OperationStatusFetcherInterface $operationStatusFetcher,
        private HostedFieldsWebhookNotificationHandler $webhookNotificationHandler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StatusHostedPaymentRequest $statusHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($statusHostedPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();

        if (self::FORCED_STATUS_CANCELED === $statusHostedPaymentRequest->getForcedStatus()) {
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
            }
        } else {
            $this->pollForOutcomeIfStillPending($payment);
        }

        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    /**
     * Fallback for when the asynchronous webhook (NotifyHostedPaymentRequestHandler /
     * HostedFieldsWebhookNotificationHandler) hasn't confirmed the outcome by the time the
     * customer is bounced back from the 3DS challenge page — a GET against the Unified API's
     * public operation endpoint, per PayPlug's own documented recommendation for a delayed or
     * lost webhook. Skipped entirely once anything else has already resolved the payment, so
     * there's nothing here to double-apply.
     *
     * The public operation endpoint's response is the same webhook-shaped payload
     * WebhookNotificationHelper::parse() already knows how to read (id/execCode/orderId/amount
     * at the top level), so applying it is unconditionally delegated to
     * HostedFieldsWebhookNotificationHandler::treat() — same cross-check against the payment,
     * same isTreated()/markTreated() dedupe, same persistence, as the real webhook path,
     * including its own no-op when the fetched operation is still THREE_DS_PENDING. This handler
     * no longer needs its own copy of that pending-code check.
     */
    private function pollForOutcomeIfStillPending(PaymentInterface $payment): void
    {
        if (\in_array($payment->getState(), self::RESOLVED_STATES, true)) {
            return;
        }

        $operationId = self::resolveOperationId($payment->getDetails());
        if (null === $operationId) {
            return;
        }

        try {
            $response = $this->operationStatusFetcher->getOperation($operationId);
        } catch (ApiException $e) {
            $this->logger->error('[PayPlug][UPC] Hosted payment status poll failed.', [
                'sylius_payment_id' => $payment->getId(),
                'hosted_fields_operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $this->webhookNotificationHandler->treat($payment, $response['body'], []);
        } catch (InvalidNotificationException $e) {
            $this->logger->error('[PayPlug][UPC] Hosted payment status poll returned a payload that could not be applied.', [
                'sylius_payment_id' => $payment->getId(),
                'hosted_fields_operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param mixed[] $details */
    private static function resolveOperationId(array $details): ?string
    {
        $operationId = $details['hosted_fields_operation_id'] ?? null;

        return \is_string($operationId) && '' !== $operationId ? $operationId : null;
    }
}
