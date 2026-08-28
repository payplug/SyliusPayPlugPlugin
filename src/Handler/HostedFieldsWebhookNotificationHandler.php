<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use PayPlug\SyliusPayPlugPlugin\Upc\CardDataFromPaymentMethodExtractor;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentOrderIdResolver;
use PayPlug\SyliusPayPlugPlugin\Upc\PayplugCardPersister;
use PayPlug\SyliusPayPlugPlugin\Upc\RefundDetailsLockKey;
use PayPlug\SyliusPayPlugPlugin\Upc\ResourceIdentifier;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Verifies and applies a Unified API (Hosted Fields) webhook notification against a Payment
 * already resolved by IpnAction, via PaymentRepositoryInterface::findOneByPayPlugPaymentId() —
 * which matches on the webhook's own payment id being present anywhere in Payment::details,
 * including the "hosted_fields_payment_id" key CaptureHostedPaymentRequestHandler stores there.
 *
 * The static, per-account IPN receiver counterpart to NotifyHostedPaymentRequestHandler, which
 * instead resolves its target Payment via a per-request PaymentRequest hash. Both share the same
 * verify/parse/idempotency primitives (WebhookNotificationHelper, IPaymentRepository); only the
 * resolution path differs. No merchant/account of ours has a way to configure a webhook secret
 * (see WebhookNotificationHelper::verifySignature()), so this handler's orderId/amount
 * cross-check against the resolved Payment is the only remaining protection against a
 * notification — genuine or forged — being applied to the wrong payment.
 *
 * Also called directly by StatusHostedPaymentRequestHandler's GET polling fallback (same
 * webhook-shaped payload, fetched instead of received) — the lock below is keyed by operationId
 * rather than by a caller-specific value so both callers serialize against each other: without
 * it, a poll and a genuinely concurrent webhook delivery for the same operation could both pass
 * isTreated() before either reaches markTreated().
 */
class HostedFieldsWebhookNotificationHandler
{
    // No admin field or other mechanism currently writes this key, so $expectedHeader below
    // always resolves empty and WebhookNotificationHelper::verifySignature() accepts every
    // notification unverified — see that method's docblock for why that's intentional here, not
    // a gap. Kept (rather than dropped) so a real secret, if one ever becomes configurable, is
    // still enforced without further changes on this end.
    private const CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER = 'payplug_webhook_authorization_header';

    private const LOCK_KEY_PREFIX = 'payplug_upc_treat_';

    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
        private IConfigurationRepository $configurationRepository,
        private ILock $lock,
        private LoggerInterface $logger,
        private PayplugCardPersister $cardPersister,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidNotificationException if the notification fails signature verification or
     *                                       parsing — the caller is expected to catch this, same
     *                                       as it already does for the legacy SDK's PayplugException.
     */
    public function treat(PaymentInterface $payment, string $rawBody, array $headers): void
    {
        $expectedHeader = $this->configurationRepository->get(self::CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER) ?? '';
        $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);

        if (PaymentOutcome::THREE_DS_PENDING === $operationData->outcome) {
            // Not a final outcome — leave the payment as-is and, crucially, do not touch
            // isTreated()/markTreated(): a later, final notification for this same operation
            // must still be free to apply once it arrives. The 0001-is-pending knowledge itself
            // now lives in payplug/unified-plugin-core's ExecCodeMapper (see its docblock for the
            // full execcode-catalog reasoning), not duplicated here. Must run before any refund
            // matching below: a 3DS-pending notification is never a refund confirmation, and
            // classifying it as one this early would be wrong regardless of whether its
            // operationId also happens to match a recorded refund.
            return;
        }

        // A notification whose operation id matches one RefundPaymentProcessor already recorded
        // under $details['refunds'] (for both full and partial UHF refunds) confirms a refund
        // operation, not the payment's own outcome — ExecCodeMapper's "0000" => PAID mapping is
        // payment-shaped and would otherwise misreport a successful refund as the payment being
        // paid. UPC has no "refund" concept in its execCode/outcome vocabulary to lean on here
        // (see ExecCodeMapper), so this classification is made locally, from ids this plugin
        // itself generated and already knows the meaning of.
        $refundAmount = self::findMatchingRefundAmount($payment, $operationData->operationId);
        $expectedAmount = $refundAmount ?? $payment->getAmount();

        if (!$this->matchesPayment($payment, $operationData, $expectedAmount)) {
            return;
        }

        if (null !== $refundAmount) {
            if (PaymentOutcome::PAID !== $operationData->outcome) {
                // The refund itself failed (or is still pending) per its own execCode — this must
                // never be forced into REFUNDED (the money never moved), nor forwarded as-is to
                // the payment's own state machine: PaymentOutcome::FAILED maps to
                // TRANSITION_FAIL (see SyliusOrderStateMutator), which means "this PAYMENT
                // failed," not "this refund attempt failed" — the underlying payment already
                // succeeded, only the refund didn't. Track/log only, so this notification stops
                // being redelivered without ever touching the Payment's own state.
                $this->logger->error('[PayPlug][UPC] Refund confirmation reports a non-success outcome.', [
                    'sylius_payment_id' => $payment->getId(),
                    'operation_id' => $operationData->operationId,
                    'outcome' => $operationData->outcome,
                    'exec_code' => $operationData->execCode,
                ]);

                if (!$this->markMatchedRefundAsFailedLocked($payment, $operationData->operationId)) {
                    // Couldn't acquire the lock guarding this payment's $details['refunds'] —
                    // RefundPaymentProcessor is creating a refund for it right now (see
                    // markMatchedRefundAsFailedLocked()'s own docblock). Return without calling
                    // applyLocked(): isTreated()/markTreated() are never touched, so this
                    // notification stays free to be redelivered and retried once that refund
                    // creation has released the lock, instead of being marked treated without its
                    // 'failed' flag ever actually being recorded.
                    return;
                }

                $this->applyLocked($payment, $rawBody, $operationData, applyOutcome: false);

                return;
            }

            $operationData->outcome = PaymentOutcome::REFUNDED;
        }

        $this->applyLocked($payment, $rawBody, $operationData);
    }

    // Split out of treat() to keep its own return count within SonarCloud's limit (php:S1142) —
    // same rationale as matchesPayment() below: this is its own self-contained "acquire, check
    // idempotency, apply" unit, not a fragment that needs to share treat()'s return budget.
    // $applyOutcome false skips the orderStateMutator call while still tracking the notification
    // as treated — used when the resolved $operationData->outcome must not reach the Payment's
    // own state machine at all (see treat()'s own non-success-refund branch above); a refund
    // confirmation never reaches maybeSaveCard() either way, since that only ever runs alongside
    // a genuine PAID outcome being applied.
    private function applyLocked(
        PaymentInterface $payment,
        string $rawBody,
        OperationData $operationData,
        bool $applyOutcome = true,
    ): void {
        $lockKey = self::LOCK_KEY_PREFIX . $operationData->operationId;
        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            // Another delivery/poll for the same operation is already being processed — whichever
            // holds the lock applies the outcome, nothing more to do here.
            return;
        }

        try {
            if ($this->paymentRepository->isTreated($operationData->operationId)) {
                return;
            }

            $this->paymentRepository->save($operationData);
            if ($applyOutcome) {
                $this->orderStateMutator->apply(ResourceIdentifier::toString($payment->getId()), $operationData->outcome);
            }
            $this->paymentRepository->markTreated($operationData->operationId);

            if (PaymentOutcome::PAID === $operationData->outcome) {
                $this->maybeSaveCard($payment, $rawBody);
            }
        } finally {
            $this->lock->release($lockKey);
        }
    }

    // A 3DS-challenge capture never gets an alias back synchronously (CaptureHostedPaymentRequestHandler
    // only sees one on a direct, frictionless success) — this webhook, fired once the challenge is
    // validated, is the only place a 3DS payment's card ever gets saved. The alias/card metadata
    // itself is already in $rawBody: confirmed the same paymentMethod.{id, card, details} shape as
    // the operation resource CaptureHostedPaymentRequestHandler fetches separately, so no extra API
    // call is needed here.
    private function maybeSaveCard(PaymentInterface $payment, string $rawBody): void
    {
        $details = $payment->getDetails();
        if (true !== ($details['hosted_fields_save_card'] ?? false)) {
            return;
        }

        $method = $payment->getMethod();
        if (null === $method) {
            return;
        }

        $cardData = CardDataFromPaymentMethodExtractor::extractFromDecoded(\json_decode($rawBody, true));
        $aliasId = $cardData['aliasId'] ?? null;
        if (null === $aliasId) {
            $this->logger->error('[PayPlug][UPC] Save-card was requested but the webhook notification carried no alias id.', [
                'sylius_payment_id' => $payment->getId(),
            ]);

            return;
        }

        $this->cardPersister->persist($aliasId, $payment, $method, $details, $cardData);
    }

    // Split out of treat() to keep its own return count within SonarCloud's limit (php:S1142) —
    // both branches here mean "nothing to apply," they just differ in whether that's expected
    // (still-pending) or a problem worth logging over (mismatch).
    private function matchesPayment(PaymentInterface $payment, OperationData $operationData, ?int $expectedAmount): bool
    {
        $expectedOrderId = PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId());
        if ($operationData->orderId !== $expectedOrderId || $operationData->amount !== $expectedAmount) {
            $this->logger->error('[PayPlug][UPC] Hosted Fields webhook notification does not match the payment it was resolved against.', [
                'sylius_payment_id' => $payment->getId(),
                'expected_order_id' => $expectedOrderId,
                'received_order_id' => $operationData->orderId,
                'expected_amount' => $expectedAmount,
                'received_amount' => $operationData->amount,
            ]);

            return false;
        }

        return true;
    }

    // $details['refunds'] entries are RefundPaymentProcessor's own — see
    // processHostedFields()/processHostedFieldsWithAmount() — {internal_id, id, amount}, id being
    // the refund operation's own id (from createRefund()'s response operationIds[0]).
    private static function findMatchingRefundAmount(PaymentInterface $payment, string $operationId): ?int
    {
        $refunds = self::resolveOwnRefunds($payment, $operationId);
        if (null === $refunds) {
            return null;
        }

        $index = self::findMatchingRefundIndex($refunds, $operationId);
        if (null === $index) {
            return null;
        }

        $entry = $refunds[$index];
        $amount = \is_array($entry) ? ($entry['amount'] ?? null) : null;

        return \is_int($amount) ? $amount : null;
    }

    /**
     * Acquires RefundDetailsLockKey before calling markMatchedRefundAsFailed() below, so this
     * read-modify-write of $details['refunds'] can't interleave with
     * RefundPaymentProcessor::processHostedFields()/processHostedFieldsWithAmount()'s own — which
     * acquire the very same key around their (network-call-spanning) read-modify-write of that
     * same array — and silently lose one of the two writes. Returns false, without calling
     * markMatchedRefundAsFailed() at all, when the lock is already held (a refund creation for
     * this payment is in progress right now): the caller must not proceed to mark this
     * notification treated in that case, so it stays free to be redelivered and retried once the
     * lock is free.
     */
    private function markMatchedRefundAsFailedLocked(PaymentInterface $payment, string $operationId): bool
    {
        $lockKey = RefundDetailsLockKey::forPaymentId($payment->getId());
        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            $this->logger->error('[PayPlug][UPC] Could not acquire the refund-details lock to flag a failed refund; a refund creation is likely in progress for this payment.', [
                'sylius_payment_id' => $payment->getId(),
                'operation_id' => $operationId,
            ]);

            return false;
        }

        try {
            self::markMatchedRefundAsFailed($payment, $operationId);
        } finally {
            $this->lock->release($lockKey);
        }

        return true;
    }

    /**
     * Neutralizes the matched refund entry's 'amount' contribution — flags it 'failed' => true —
     * so a later RefundPaymentProcessor::processHostedFields() full-refund call (which sums every
     * $details['refunds'] entry to derive the remaining balance still owed) doesn't count money
     * that was accepted synchronously by createRefund() but never actually moved, per this same
     * notification's own non-success outcome. The entry itself (id/amount) is kept, not removed,
     * as an audit trail of the failed attempt. Only ever called while holding RefundDetailsLockKey
     * — see markMatchedRefundAsFailedLocked() above, its only caller.
     */
    private static function markMatchedRefundAsFailed(PaymentInterface $payment, string $operationId): void
    {
        $refunds = self::resolveOwnRefunds($payment, $operationId);
        if (null === $refunds) {
            return;
        }

        $index = self::findMatchingRefundIndex($refunds, $operationId);
        if (null === $index || !\is_array($refunds[$index])) {
            return;
        }

        $refunds[$index]['failed'] = true;
        $details = $payment->getDetails();
        $details['refunds'] = $refunds;
        $payment->setDetails($details);
    }

    /**
     * @return mixed[]|null $details['refunds'] as an array, or null when $operationId is either
     *      the known payment-creation operation id (never a refund — see the inline comment
     *      below) or $details['refunds'] itself isn't a usable array.
     */
    private static function resolveOwnRefunds(PaymentInterface $payment, string $operationId): ?array
    {
        $details = $payment->getDetails();

        // The original payment-creation notification always carries the exact operation id
        // CaptureHostedPaymentRequestHandler recorded under hosted_fields_operation_id at
        // creation time — never a refund. The unresolved-entry fallback in
        // findMatchingRefundIndex() must not misclassify a delayed/redelivered copy of THAT
        // notification as an unrelated refund just because a refund with no captured id also
        // happens to exist on this payment.
        $paymentOperationId = $details['hosted_fields_operation_id'] ?? null;
        if (\is_string($paymentOperationId) && $paymentOperationId === $operationId) {
            return null;
        }

        $refunds = $details['refunds'] ?? null;

        return \is_array($refunds) ? $refunds : null;
    }

    /**
     * @param mixed[] $refunds
     *
     * A refund entry with a null id means RefundPaymentProcessor's own createRefund() call
     * returned a 2xx response whose body carried no operationIds (logged there as an error at
     * the time) — this confirmation is the only remaining way to learn which refund it belongs
     * to, so fall back to the most recent such unresolved entry rather than dropping the
     * notification entirely (or, worse, letting it fall through unmatched and get misapplied as
     * a plain payment confirmation). Ambiguous only if more than one refund for the same payment
     * independently hit that same malformed-response edge case, which the upstream error log
     * already flags as needing manual attention.
     */
    private static function findMatchingRefundIndex(array $refunds, string $operationId): ?int
    {
        $unresolvedIndex = null;

        foreach ($refunds as $index => $refund) {
            if (!\is_int($index) || !\is_array($refund) || !\is_int($refund['amount'] ?? null)) {
                continue;
            }

            $refundOperationId = $refund['id'] ?? null;
            if ($refundOperationId === $operationId) {
                return $index;
            }

            if (null === $refundOperationId) {
                $unresolvedIndex = $index;
            }
        }

        return $unresolvedIndex;
    }
}
