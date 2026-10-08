<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\ILock;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Refunds are keyed by hosted_fields_payment_id, which the creation response sometimes lacks; the
 * paid payment's webhook carries it as its top-level id. Called by
 * HostedFieldsWebhookNotificationHandler for a paid payment notification, under RefundDetailsLockKey
 * (shared with RefundPaymentProcessor). The write is flushed by the handler's own save() and
 * markTreated() calls.
 */
final class HostedFieldsPaymentIdBackfiller
{
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private ILock $lock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool false when the payment awaits this id but the lock is held: the caller must leave
     *              the notification untreated so that a redelivery retries
     */
    public function backfill(PaymentInterface $payment, string $rawBody): bool
    {
        $webhookPaymentId = HostedFieldsPaymentIdExtractor::fromWebhook(\json_decode($rawBody, true));
        if (null === $webhookPaymentId || !$this->awaitsPaymentId($payment, $webhookPaymentId)) {
            return true;
        }

        $lockKey = RefundDetailsLockKey::forPaymentId($payment->getId());
        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            return false;
        }

        try {
            if ($this->awaitsPaymentId($payment, $webhookPaymentId)) {
                $payment->setDetails([...$payment->getDetails(), 'hosted_fields_payment_id' => $webhookPaymentId]);
                $this->logger->info('[PayPlug][UPC] Payment id missing from the creation response, backfilled from the webhook.', [
                    'sylius_payment_id' => $payment->getId(),
                    'hosted_fields_payment_id' => $webhookPaymentId,
                ]);
            }
        } finally {
            $this->lock->release($lockKey);
        }

        return true;
    }

    /**
     * True only when the payment has no stored payment id and its creation has committed: both
     * capture handlers write their created_at key in the same setDetails() call as the ids, so
     * writing before it exists would be overwritten by the capture handler's own stale snapshot.
     * An id equal to the payment's creation operation id is never a payment id (the status poll
     * passes the operation body to the webhook handler). A stored id is never overwritten.
     *
     * @phpstan-impure
     */
    private function awaitsPaymentId(PaymentInterface $payment, string $webhookPaymentId): bool
    {
        $details = $payment->getDetails();
        if ($webhookPaymentId === ($details['hosted_fields_operation_id'] ?? null)) {
            return false;
        }

        $storedPaymentId = $details['hosted_fields_payment_id'] ?? null;
        if (\is_string($storedPaymentId) && '' !== $storedPaymentId) {
            if ($storedPaymentId !== $webhookPaymentId) {
                $this->logger->error('[PayPlug][UPC] Webhook payment id differs from the stored one, keeping the stored one.', [
                    'sylius_payment_id' => $payment->getId(),
                    'stored_payment_id' => $storedPaymentId,
                    'webhook_payment_id' => $webhookPaymentId,
                ]);
            }

            return false;
        }

        return isset($details['hosted_fields_created_at']) || isset($details['alias_payment_created_at']);
    }
}
