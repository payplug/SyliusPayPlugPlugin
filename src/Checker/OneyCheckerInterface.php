<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use Sylius\Component\Core\Model\OrderInterface;

interface OneyCheckerInterface
{
    public function isEnabled(): bool;

    /**
     * For a given price, check if oney allow it
     */
    public function isPriceEligible(int $price, string $currency = 'EUR'): bool;

    /**
     * For x products, check if oney allow it
     */
    public function isNumberOfProductEligible(int $numberOfProduct): bool;
}
