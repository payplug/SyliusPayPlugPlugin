<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

interface OneyCheckerInterface
{
    public function isEnabled(): bool;

    /**
     * For a given price, check if oney allow it.
     */
    public function isPriceEligible(int $price, string $currency = 'EUR'): bool;

    /**
     * For x products, check if oney allow it.
     */
    public function isNumberOfProductEligible(int $numberOfProduct): bool;

    /**
     * Check if shipping and / or billing address is in France.
     */
    public function isCountryEligible(?string $shippingCountry, ?string $billingCountry): bool;
}
