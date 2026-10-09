<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * The lock guarding read-modify-write access to a Hosted Fields payment's deferred-capture
 * bookkeeping (see AuthorizationDetails) — shared by AuthorizedPaymentOperationProcessor (whose
 * read-modify-write spans the capture/cancel network call) and
 * HostedFieldsWebhookNotificationHandler (flagging one of those operations failed), for the same
 * lost-update reason RefundDetailsLockKey documents for refunds.
 */
final class AuthorizationLockKey
{
    private const PREFIX = 'payplug_upc_authorization_';

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
