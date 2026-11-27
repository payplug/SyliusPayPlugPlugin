<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

/**
 * The card metadata the Hosted Fields SDK returns with the token (last 4 digits, expiry, issuing
 * country), kept apart from HostedFieldsCaptureData so its constructor stays under SonarCloud
 * php:S107. Stored on the payment to describe a saved card; never sent to the Unified API.
 */
final class HostedFieldsCardDetails
{
    public function __construct(
        public readonly string $last4,
        public readonly ?int $expirationMonth,
        public readonly ?int $expirationYear,
        public readonly string $countryCode,
    ) {
    }
}
