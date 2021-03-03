<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;

final class OneyChecker implements OneyCheckerInterface
{
    private const ONEY_PERMISSION_FIELD = 'can_use_oney';

    /** @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface */
    private $client;

    public function __construct(PayPlugApiClientInterface $oneyClient)
    {
        $this->client = $oneyClient;
    }

    public function isEnabled(): bool
    {
        $permissions = $this->client->getPermissions();

        return (bool) ($permissions[self::ONEY_PERMISSION_FIELD] ?? false);
    }

    public function isPriceEligible(int $price, string $currency = 'EUR'): bool
    {
        $account = $this->client->getAccount();
        $minAmount = $account['configuration']['oney']['min_amounts'][$currency] ?? null;
        $maxAmount = $account['configuration']['oney']['max_amounts'][$currency] ?? null;

        if (null === $minAmount || null === $maxAmount) {
            // amount not found, consider not eligible
            return false;
        }

        return $price >= $minAmount && $price <= $maxAmount;
    }

    public function isNumberOfProductEligible(int $numberOfProduct): bool
    {
        return $numberOfProduct <= OneyGatewayFactory::MAX_ITEMS;
    }

    public function isCountryEligible(?string $shippingCountry, ?string $billingAddress): bool
    {
        if ($shippingCountry === null || $billingAddress === null) {
            return false;
        }

        $allowedCountries = $this->client->getAccount()['configuration']['oney']['allowed_countries'];

        if (!in_array($shippingCountry, $allowedCountries, true) ||
            !in_array($billingAddress, $allowedCountries, true)) {
            return false;
        }

        return true;
    }
}
