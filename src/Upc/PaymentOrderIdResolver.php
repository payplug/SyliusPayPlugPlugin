<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Resolves the orderId sent to the Unified API for a payment: the order's own number once it
 * exists, falling back to the payment's own id when it doesn't yet — shared by
 * PaymentCaptureContextBuilder (building the request sent to PayPlug) and
 * HostedFieldsWebhookNotificationHandler (cross-checking a webhook against what was originally
 * sent), so both resolve to the exact same value.
 */
final class PaymentOrderIdResolver
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function resolve(?OrderInterface $order, mixed $paymentId): string
    {
        return $order?->getNumber() ?? ResourceIdentifier::toString($paymentId);
    }
}
