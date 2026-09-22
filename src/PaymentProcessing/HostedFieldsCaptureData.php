<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

/**
 * The Hosted Fields form submission data captured by PostPaymentSelectEventSubscriber and handed
 * to HostedFieldsPaymentProcessorInterface::process() — grouped into one object rather than
 * passed as individual parameters (SonarCloud php:S107, max 7 parameters).
 */
final class HostedFieldsCaptureData
{
    public function __construct(
        public readonly string $hfToken,
        public readonly string $selectedBrand,
        public readonly bool $saveCard,
        public readonly string $last4,
        public readonly ?int $expirationMonth,
        public readonly ?int $expirationYear,
        public readonly string $countryCode,
    ) {
    }
}
