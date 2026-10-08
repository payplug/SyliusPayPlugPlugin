<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Reads the payment id from a decoded Unified API webhook body: its top-level id, which is a
 * payment id only on a PAYMENT operation (a refund operation's id identifies the refund).
 */
final class HostedFieldsPaymentIdExtractor
{
    private const PAYMENT_OPERATION_TYPE = 'PAYMENT';

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function fromWebhook(mixed $decoded): ?string
    {
        if (!\is_array($decoded) || self::PAYMENT_OPERATION_TYPE !== ($decoded['operationType'] ?? null)) {
            return null;
        }

        $id = $decoded['id'] ?? null;

        return \is_string($id) && '' !== $id ? $id : null;
    }
}
