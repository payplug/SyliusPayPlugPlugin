<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

/**
 * Tells a payment creation refused for the billing country or the amount from any other refusal,
 * using only the field names of the `details` tree of the PayPlug error body.
 */
final class PproRefusalClassifier
{
    public const COUNTRY = 'country';

    public const AMOUNT = 'amount';

    public function classify(?string $responseBody): ?string
    {
        $details = $this->readDetails($responseBody);
        if (null === $details) {
            return null;
        }

        if (\array_key_exists('amount', $details)) {
            return self::AMOUNT;
        }

        $billing = $details['billing'] ?? null;

        return \is_array($billing) && \array_key_exists('country', $billing) ? self::COUNTRY : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readDetails(?string $responseBody): ?array
    {
        $body = null === $responseBody || '' === $responseBody ? null : json_decode($responseBody, true);
        $details = \is_array($body) ? ($body['details'] ?? null) : null;

        return \is_array($details) ? $details : null;
    }
}
