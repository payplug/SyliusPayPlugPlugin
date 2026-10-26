<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;

/**
 * Reads the stored `payment_method` block of a gateway config, completed with the range the former
 * Scalapay-only form saved in cents under `min_amount`/`max_amount`. That range stands in for the
 * merchant range until a range is entered or the PayPlug limits are first stored: the form never
 * shows it, so it must not be lost when the block is created first by another setting.
 */
final class PproMerchantSettings
{
    private const LEGACY_SCALAPAY_MIN_AMOUNT = 'min_amount';

    private const LEGACY_SCALAPAY_MAX_AMOUNT = 'max_amount';

    /**
     * @param array<array-key, mixed> $config the whole gateway config
     *
     * @return PproConfig|null null when nothing readable is stored and there is no legacy range
     */
    public static function read(?string $factoryName, array $config): ?PproConfig
    {
        $stored = PproConfig::fromArray($config[PproConfig::CONFIG_KEY] ?? null);
        if (ScalapayGatewayFactory::FACTORY_NAME !== $factoryName || !self::awaitsLegacyRange($stored)) {
            return $stored;
        }

        $min = self::readLegacyAmount($config[self::LEGACY_SCALAPAY_MIN_AMOUNT] ?? null);
        $max = self::readLegacyAmount($config[self::LEGACY_SCALAPAY_MAX_AMOUNT] ?? null);
        if (null === $min && null === $max) {
            return $stored;
        }

        return new PproConfig(
            $stored?->name,
            $stored->enabled ?? true,
            $stored?->allowedCountries,
            $stored?->defaultMin,
            $stored?->defaultMax,
            $min,
            $max,
            $stored?->configuredCountries,
            $stored?->live,
            $stored?->refreshedAt,
        );
    }

    /**
     * The merchant's own narrowing only, without the PayPlug limits stored next to it.
     *
     * @param array<array-key, mixed> $config the whole gateway config
     */
    public static function readMerchantOnly(?string $factoryName, array $config): ?PproConfig
    {
        $settings = self::read($factoryName, $config);

        return null === $settings ? null : new PproConfig(
            null,
            true,
            null,
            null,
            null,
            $settings->configuredMin,
            $settings->configuredMax,
            $settings->configuredCountries,
        );
    }

    private static function awaitsLegacyRange(?PproConfig $stored): bool
    {
        return null === $stored ||
            (null === $stored->refreshedAt && null === $stored->configuredMin && null === $stored->configuredMax);
    }

    private static function readLegacyAmount(mixed $amount): ?int
    {
        return \is_int($amount) && $amount >= 0 ? $amount : null;
    }
}
