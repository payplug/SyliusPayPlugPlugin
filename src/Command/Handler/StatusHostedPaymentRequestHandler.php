<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
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

    // The Unified API's transient "challenge required" code — never a final outcome. Left
    // unmapped by ExecCodeMapper on purpose (it only ever sees final codes from the webhook), so
    // it must be special-cased here before reaching it: 0001 is FAILED under that mapper's "only
    // 0000 is PAID" rule, which would wrongly fail a payment still awaiting the customer.
    private const EXEC_CODE_PENDING = '0001';

    // States that already carry a final outcome — reached by the webhook, by the synchronous
    // branch in CaptureHostedPaymentRequestHandler, or by a previous poll. Anything else
    // (observed in practice: the payment stays "new" — Sylius's state machine is never driven
    // to "processing" for this flow, despite what SyliusOrderStateMutator's docblock assumes) is
    // still awaiting an outcome and is worth polling for.
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
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
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
     * Fallback for when the asynchronous webhook (NotifyHostedPaymentRequestHandler) hasn't
     * confirmed the outcome by the time the customer is bounced back from the 3DS challenge page
     * — a GET against the Unified API, per PayPlug's own documented recommendation for a delayed
     * or lost webhook. Skipped entirely once anything else has already resolved the payment (the
     * webhook, or the synchronous branch in CaptureHostedPaymentRequestHandler), so there's
     * nothing here to double-apply — and IOrderStateMutator is idempotent besides.
     *
     * Polls the *operation* (GET /processing-operations/operations/{id}), not the payment: the
     * payment representation carries no execCode at all, while the operation's does
     * (transaction.status.execCode), in the same vocabulary ExecCodeMapper already maps from the
     * webhook and payment-creation flows. isTreated()/markTreated() are keyed by the payment id
     * (not the operation id polled here) to match what the webhook path actually dedupes on:
     * WebhookNotificationHelper::parse() populates OperationData's operationId from the webhook
     * payload's top-level payment id, not from a per-operation id — so whichever of the two
     * (webhook or this poll) arrives first is correctly recognized by the other as already done.
     */
    private function pollForOutcomeIfStillPending(PaymentInterface $payment): void
    {
        if (\in_array($payment->getState(), self::RESOLVED_STATES, true)) {
            return;
        }

        $ids = self::resolvePollingIds($payment->getDetails());
        if (null === $ids) {
            return;
        }
        [$operationId, $paymentId] = $ids;

        try {
            $response = $this->operationStatusFetcher->getOperation($operationId);
        } catch (OperationNotFoundException | ApiException $e) {
            $this->logger->error('[PayPlug][UPC] Hosted payment status poll failed.', [
                'sylius_payment_id' => $payment->getId(),
                'hosted_fields_operation_id' => $operationId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $execCode = self::extractExecCode($response['body']);
        if (!\is_string($execCode) || '' === $execCode || self::EXEC_CODE_PENDING === $execCode) {
            // No final code yet (the challenge genuinely hasn't been completed) — leave the
            // payment as-is; the webhook or a later poll will resolve it.
            return;
        }

        if ($this->paymentRepository->isTreated($paymentId)) {
            return;
        }

        $this->orderStateMutator->apply(self::idToString($payment->getId()), ExecCodeMapper::toPaymentOutcome($execCode));
        $this->paymentRepository->markTreated($paymentId);
    }

    /**
     * @param mixed[] $details
     *
     * @return array{0: string, 1: string}|null
     */
    private static function resolvePollingIds(array $details): ?array
    {
        $operationId = $details['hosted_fields_operation_id'] ?? null;
        $paymentId = $details['hosted_fields_payment_id'] ?? null;
        if (!\is_string($operationId) || '' === $operationId || !\is_string($paymentId) || '' === $paymentId) {
            return null;
        }

        return [$operationId, $paymentId];
    }

    private static function extractExecCode(string $operationBody): ?string
    {
        $body = \json_decode($operationBody, true);
        $transaction = \is_array($body) ? ($body['transaction'] ?? null) : null;
        $status = \is_array($transaction) ? ($transaction['status'] ?? null) : null;
        $execCode = \is_array($status) ? ($status['execCode'] ?? null) : null;

        return \is_string($execCode) ? $execCode : null;
    }

    private static function idToString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
