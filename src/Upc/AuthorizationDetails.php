<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Output\PaymentOutput;

/**
 * Read/write view over the deferred-capture bookkeeping a Hosted Fields payment carries in its
 * own Payment::details — the one place that knows these keys, shared by the capture handlers
 * (which open an authorization), AuthorizedPaymentOperationProcessor (which captures/cancels it)
 * and HostedFieldsWebhookNotificationHandler (which confirms those operations asynchronously).
 *
 * Every capture and cancellation Sylius triggers is recorded with the operation id the Unified
 * API returned for it — the same convention RefundPaymentProcessor follows for refunds — so that
 * the eventual webhook for that operation id can be told apart from the authorization's own:
 * ExecCodeMapper maps every successful execCode to PaymentOutcome::PAID, whichever operation it
 * confirms, and it is only by knowing which call produced the id that "0000" can be read as
 * "authorized", "captured" or "cancelled".
 *
 * Amounts are always in the payment's minor units. A capture/cancellation entry flagged
 * 'failed' => true (its synchronous call was accepted but its webhook later reported it did not
 * go through) no longer counts toward the totals, mirroring RefundPaymentProcessor's handling of
 * a failed refund.
 */
final class AuthorizationDetails
{
    public const DEFERRED = 'hosted_fields_deferred_capture';

    public const AUTHORIZED_AMOUNT = 'hosted_fields_authorized_amount';

    public const MAX_CAPTURE_DATE = 'hosted_fields_max_capture_date';

    public const CAPTURES = 'hosted_fields_captures';

    public const CANCELLATIONS = 'hosted_fields_cancellations';

    public const OPERATION_CAPTURE = 'capture';

    public const OPERATION_CANCELLATION = 'cancellation';

    private const LOCK_KEY_PREFIX = 'payplug_upc_authorization_';

    /**
     * How long before maxCaptureDate the admin starts warning the merchant that the
     * authorization is about to lapse.
     */
    private const EXPIRY_WARNING_INTERVAL = 'PT48H';

    /**
     * @param mixed[] $details
     */
    private function __construct(private array $details)
    {
    }

    /**
     * @param mixed[] $details
     */
    public static function fromDetails(array $details): self
    {
        return new self($details);
    }

    /**
     * The lock guarding read-modify-write access to this bookkeeping — shared by
     * AuthorizedPaymentOperationProcessor (whose read-modify-write spans the capture/cancel
     * network call) and HostedFieldsWebhookNotificationHandler (flagging one of those operations
     * failed), for the same lost-update reason RefundDetailsLockKey documents for refunds.
     */
    public static function lockKey(mixed $paymentId): string
    {
        return self::LOCK_KEY_PREFIX . ResourceIdentifier::toString($paymentId);
    }

    /**
     * The details a freshly created authorization-only payment starts with. $fallbackAmount (the
     * Sylius payment's own amount) only stands in when the creation response carried no amount
     * at all — e.g. a 3DS-pending response, whose authorization is not granted yet.
     *
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    public static function open(array $details, PaymentOutput $output, int $fallbackAmount): array
    {
        return [
            ...$details,
            self::DEFERRED => true,
            self::AUTHORIZED_AMOUNT => $output->remainingCapturableAmount ?? $fallbackAmount,
            self::MAX_CAPTURE_DATE => $output->maxCaptureDate,
            self::CAPTURES => [],
            self::CANCELLATIONS => [],
        ];
    }

    /**
     * A 3DS authorization only learns its deadline from the webhook confirming it, not from the
     * creation response — read it off that notification's own raw body when present.
     *
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    public static function withMaxCaptureDateFromBody(array $details, string $rawBody): array
    {
        $decoded = \json_decode($rawBody, true);
        $maxCaptureDate = \is_array($decoded) ? ($decoded['maxCaptureDate'] ?? null) : null;
        if (!\is_string($maxCaptureDate) || '' === $maxCaptureDate) {
            return $details;
        }

        return [...$details, self::MAX_CAPTURE_DATE => $maxCaptureDate];
    }

    /**
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    public static function withOperation(array $details, string $operation, ?string $operationId, int $amount): array
    {
        $key = self::keyFor($operation);
        $entries = \is_array($details[$key] ?? null) ? $details[$key] : [];
        $entries[] = ['id' => $operationId, 'amount' => $amount];

        return [...$details, $key => $entries];
    }

    /**
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    public static function withOperationFailed(array $details, string $operation, string $operationId): array
    {
        $key = self::keyFor($operation);
        $entries = \is_array($details[$key] ?? null) ? $details[$key] : [];
        foreach ($entries as $index => $entry) {
            if (\is_array($entry) && ($entry['id'] ?? null) === $operationId) {
                $entries[$index] = [...$entry, 'failed' => true];
            }
        }

        return [...$details, $key => $entries];
    }

    public function isDeferred(): bool
    {
        return true === ($this->details[self::DEFERRED] ?? false);
    }

    /**
     * ExecCodeMapper reads every successful execCode as PAID; for the authorization-only
     * creation of a deferred payment, that success means "authorized", never "paid" — no money
     * has moved yet.
     */
    public function resolveCreationOutcome(string $outcome): string
    {
        return $this->isDeferred() && PaymentOutcome::PAID === $outcome ? PaymentOutcome::AUTHORIZED : $outcome;
    }

    /**
     * @return array{operation: string, amount: int}|null which of this payment's own recorded
     *      captures/cancellations $operationId belongs to, if any
     */
    public function findOperation(string $operationId): ?array
    {
        foreach ([self::OPERATION_CAPTURE, self::OPERATION_CANCELLATION] as $operation) {
            foreach ($this->entries($operation, includeFailed: true) as $entry) {
                if ($entry['id'] === $operationId) {
                    return ['operation' => $operation, 'amount' => $entry['amount']];
                }
            }
        }

        return null;
    }

    public function authorizedAmount(): int
    {
        $amount = $this->details[self::AUTHORIZED_AMOUNT] ?? 0;

        return \is_int($amount) ? $amount : 0;
    }

    public function capturedAmount(): int
    {
        return self::sum($this->entries(self::OPERATION_CAPTURE));
    }

    public function cancelledAmount(): int
    {
        return self::sum($this->entries(self::OPERATION_CANCELLATION));
    }

    public function remainingAmount(): int
    {
        return \max(0, $this->authorizedAmount() - $this->capturedAmount() - $this->cancelledAmount());
    }

    public function hasCaptures(): bool
    {
        return [] !== $this->entries(self::OPERATION_CAPTURE);
    }

    public function maxCaptureDate(): ?\DateTimeImmutable
    {
        $value = $this->details[self::MAX_CAPTURE_DATE] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Unknown deadline is never "expired": the Unified API stays the authority and refuses a
     * capture on a lapsed authorization itself (AuthorizationExpiredException).
     */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        $maxCaptureDate = $this->maxCaptureDate();

        return null !== $maxCaptureDate && $now >= $maxCaptureDate;
    }

    public function isExpiringSoon(\DateTimeImmutable $now): bool
    {
        $maxCaptureDate = $this->maxCaptureDate();

        return null !== $maxCaptureDate &&
            !$this->isExpired($now) &&
            $now->add(new \DateInterval(self::EXPIRY_WARNING_INTERVAL)) >= $maxCaptureDate;
    }

    /**
     * Changes with every capture/cancellation recorded, failed or not. Carried by the admin forms
     * and checked again under the lock, so a replayed or double-submitted form — built against a
     * state that has since moved on — is refused rather than triggering a second real operation.
     */
    public function version(): int
    {
        return \count($this->entries(self::OPERATION_CAPTURE, includeFailed: true)) +
            \count($this->entries(self::OPERATION_CANCELLATION, includeFailed: true));
    }

    private static function keyFor(string $operation): string
    {
        return match ($operation) {
            self::OPERATION_CAPTURE => self::CAPTURES,
            self::OPERATION_CANCELLATION => self::CANCELLATIONS,
            default => throw new \InvalidArgumentException(\sprintf('Unknown authorization operation "%s".', $operation)),
        };
    }

    /**
     * @return list<array{id: string|null, amount: int}>
     */
    private function entries(string $operation, bool $includeFailed = false): array
    {
        $raw = $this->details[self::keyFor($operation)] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_int($entry['amount'] ?? null)) {
                continue;
            }
            if (!$includeFailed && true === ($entry['failed'] ?? false)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $entries[] = ['id' => \is_string($id) ? $id : null, 'amount' => $entry['amount']];
        }

        return $entries;
    }

    /**
     * @param list<array{id: string|null, amount: int}> $entries
     */
    private static function sum(array $entries): int
    {
        return \array_sum(\array_column($entries, 'amount'));
    }
}
