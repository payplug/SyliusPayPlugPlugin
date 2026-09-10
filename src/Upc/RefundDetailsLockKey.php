<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * The lock key guarding read-modify-write access to a Hosted Fields payment's own
 * Payment::details['refunds'] bookkeeping — shared by RefundPaymentProcessor (creating a refund,
 * which reads/writes this same array around a network call) and
 * HostedFieldsWebhookNotificationHandler (flagging a refund confirmed failed by its async
 * webhook), so the two writers serialize against each other, not just against themselves.
 * Without a shared key, the two independent read-modify-write sequences could interleave and
 * silently drop one of the two writes (a lost update) — see RefundPaymentProcessor's own
 * processHostedFields()/processHostedFieldsWithAmount() docblocks for the full scenario.
 */
final class RefundDetailsLockKey
{
    private const PREFIX = 'payplug_upc_refund_details_';

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function forPaymentId(mixed $paymentId): string
    {
        return self::PREFIX . ResourceIdentifier::toString($paymentId);
    }
}
