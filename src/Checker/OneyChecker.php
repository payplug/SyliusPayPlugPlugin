<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OneyChecker implements OneyCheckerInterface
{
    private const ONEY_PERMISSION_FIELD = 'can_use_oney';

    public function __construct(
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.oney')]
        private PayPlugApiClientInterface $client,
    ) {
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

    public function isCountryEligible(?string $shippingCountry, ?string $billingCountry): bool
    {
        if (null === $shippingCountry || null === $billingCountry) {
            return false;
        }

        if ($shippingCountry !== $billingCountry) {
            return false;
        }

        $allowedCountries = $this->client->getAccount()['configuration']['oney']['allowed_countries'];

        if (!in_array($shippingCountry, $allowedCountries, true) ||
            !in_array($billingCountry, $allowedCountries, true)) {
            return false;
        }

        return true;
    }
}
