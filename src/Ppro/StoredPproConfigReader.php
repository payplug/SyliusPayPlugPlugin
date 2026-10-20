<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

/**
 * Reads the `payment_method` block of a gateway config, which is a plain serialized array that a
 * direct DB edit or an import can leave in any shape.
 */
final class StoredPproConfigReader
{
    /**
     * @param array<array-key, mixed> $stored
     *
     * @throws \UnexpectedValueException when a stored value does not have the expected type
     */
    public static function read(array $stored): PproConfig
    {
        $defaultAmount = self::readAmountRange($stored['default_amount'] ?? null);
        $configuredAmount = self::readAmountRange($stored['configured_amount'] ?? null);

        return new PproConfig(
            self::readString($stored['name'] ?? null),
            self::readBool($stored['enabled'] ?? null) ?? true,
            self::readCountries($stored['allowed_countries'] ?? null),
            $defaultAmount[0],
            $defaultAmount[1],
            $configuredAmount[0],
            $configuredAmount[1],
            self::readCountries($stored['configured_countries'] ?? null),
            self::readBool($stored['live'] ?? null),
            self::readString($stored['refreshed_at'] ?? null),
        );
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private static function readAmountRange(mixed $range): array
    {
        if (null === $range) {
            return [null, null];
        }

        if (!\is_array($range)) {
            throw new \UnexpectedValueException('Amount range must be an array.');
        }

        return [self::readAmount($range['min'] ?? null), self::readAmount($range['max'] ?? null)];
    }

    private static function readAmount(mixed $amount): ?int
    {
        if (null === $amount) {
            return null;
        }

        if (!\is_int($amount) || $amount < 0) {
            throw new \UnexpectedValueException('Amount must be a positive integer.');
        }

        return $amount;
    }

    /**
     * @return list<string>|null
     */
    private static function readCountries(mixed $countries): ?array
    {
        if (null === $countries) {
            return null;
        }

        if (!\is_array($countries)) {
            throw new \UnexpectedValueException('Country list must be an array.');
        }

        $codes = [];
        foreach ($countries as $country) {
            if (!\is_string($country)) {
                throw new \UnexpectedValueException('Country code must be a string.');
            }
            $codes[] = $country;
        }

        return $codes;
    }

    private static function readString(mixed $value): ?string
    {
        if (null !== $value && !\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string.');
        }

        return $value;
    }

    private static function readBool(mixed $value): ?bool
    {
        if (null !== $value && !\is_bool($value)) {
            throw new \UnexpectedValueException('Expected a boolean.');
        }

        return $value;
    }
}
