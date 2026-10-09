<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

/**
 * Reads deferred-capture data off the raw body of a Unified API webhook notification.
 */
final class AuthorizationWebhookBody
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * A 3DS authorization only learns its deadline from the webhook confirming it, not from the
     * creation response — read it off that notification's own raw body when present.
     *
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    public static function withMaxCaptureDate(array $details, string $rawBody): array
    {
        $decoded = \json_decode($rawBody, true);
        $maxCaptureDate = \is_array($decoded) ? ($decoded['maxCaptureDate'] ?? null) : null;
        if (!\is_string($maxCaptureDate) || '' === $maxCaptureDate) {
            return $details;
        }

        return [...$details, AuthorizationDetails::MAX_CAPTURE_DATE => $maxCaptureDate];
    }
}
