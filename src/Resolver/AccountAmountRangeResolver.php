<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use Webmozart\Assert\Assert;

/**
 * Normalizes a PayPlug `getAccount()` payload into a min/max amount range per currency,
 * applying the given payment method's own override (when present and well-formed) over the
 * account-level defaults.
 */
final class AccountAmountRangeResolver
{
    /**
     * @param array<array-key, mixed> $account
     *
     * @return array<string, array{min_amount: int, max_amount: int}>
     */
    public function resolve(array $account, ?string $paymentMethodKey): array
    {
        $configuration = $account['configuration'] ?? [];
        Assert::isArray($configuration);
        $defaultMinAmounts = $configuration['min_amounts'] ?? [];
        Assert::isArray($defaultMinAmounts);
        $defaultMaxAmounts = $configuration['max_amounts'] ?? [];
        Assert::isArray($defaultMaxAmounts);

        if (null !== $paymentMethodKey) {
            $paymentMethods = $account['payment_methods'] ?? [];
            Assert::isArray($paymentMethods);
            $pmData = $paymentMethods[$paymentMethodKey] ?? [];
            Assert::isArray($pmData);
            $minAmounts = isset($pmData['min_amounts']) && \is_array($pmData['min_amounts']) ? $pmData['min_amounts'] : $defaultMinAmounts;
            $maxAmounts = isset($pmData['max_amounts']) && \is_array($pmData['max_amounts']) ? $pmData['max_amounts'] : $defaultMaxAmounts;
        } else {
            $minAmounts = $defaultMinAmounts;
            $maxAmounts = $defaultMaxAmounts;
        }

        $currencies = [];
        foreach ($minAmounts as $currency => $min) {
            Assert::string($currency);
            Assert::integer($min);
            if (isset($maxAmounts[$currency]) && \is_int($maxAmounts[$currency])) {
                $currencies[$currency] = ['min_amount' => $min, 'max_amount' => $maxAmounts[$currency]];
            }
        }

        return $currencies;
    }
}
