<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

/**
 * Reads the billing countries a PayPlug `getAccount()` payload allows for a payment method. PayPlug
 * advertises "no restriction" by omitting the list, by an empty list or with `["ALL"]`: all three
 * resolve to null.
 */
final class AccountAllowedCountriesResolver
{
    /**
     * @param array<array-key, mixed> $account
     *
     * @throws \UnexpectedValueException when the payload does not have the expected shape
     *
     * @return list<string>|null
     */
    public function resolve(array $account, ?string $paymentMethodKey): ?array
    {
        $allowedCountries = null === $paymentMethodKey ? null : $this->readEntry($account, $paymentMethodKey)['allowed_countries'] ?? null;
        if (null === $allowedCountries) {
            return null;
        }

        if (!\is_array($allowedCountries)) {
            throw new \UnexpectedValueException('The allowed countries are not a list.');
        }

        $codes = [];
        foreach ($allowedCountries as $country) {
            if (!\is_string($country)) {
                throw new \UnexpectedValueException('An allowed country is not a string.');
            }
            $codes[] = $country;
        }

        return [] === $codes || \in_array('ALL', $codes, true) ? null : $codes;
    }

    /**
     * @param array<array-key, mixed> $account
     *
     * @return array<array-key, mixed>
     */
    private function readEntry(array $account, string $paymentMethodKey): array
    {
        $paymentMethods = $account['payment_methods'] ?? [];
        $entry = \is_array($paymentMethods) ? ($paymentMethods[$paymentMethodKey] ?? []) : null;

        if (!\is_array($entry)) {
            throw new \UnexpectedValueException('The payment methods of the PayPlug account are malformed.');
        }

        return $entry;
    }
}
