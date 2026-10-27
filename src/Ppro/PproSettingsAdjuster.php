<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

/**
 * Fits the merchant's PPRO settings into PayPlug limits.
 */
final class PproSettingsAdjuster
{
    /**
     * An empty side (null) follows the PayPlug limit and stays empty. A set side is clamped into the
     * PayPlug limits; when the effective range is left empty, both sides are reset to follow them.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function clampAmounts(?int $min, ?int $max, ?int $defaultMin, ?int $defaultMax): array
    {
        $effectiveMin = $min ?? $defaultMin;
        $effectiveMax = $max ?? $defaultMax;

        if (self::isGreater($effectiveMin, $defaultMax) || self::isGreater($defaultMin, $effectiveMax) || self::isGreater($effectiveMin, $effectiveMax)) {
            return [null, null];
        }

        return [self::clamp($min, $defaultMin, $defaultMax), self::clamp($max, $defaultMin, $defaultMax)];
    }

    private static function clamp(?int $value, ?int $floor, ?int $ceiling): ?int
    {
        if (self::isGreater($floor, $value)) {
            return $floor;
        }

        return self::isGreater($value, $ceiling) ? $ceiling : $value;
    }

    private static function isGreater(?int $left, ?int $right): bool
    {
        return null !== $left && null !== $right && $left > $right;
    }

    /**
     * @param list<string>|null $configured
     * @param list<string>|null $allowed
     *
     * @return list<string>|null
     */
    public static function intersectCountries(?array $configured, ?array $allowed): ?array
    {
        if (null === $configured || null === $allowed) {
            return $configured;
        }

        // Empty when none of the merchant's countries is allowed any more: the method is then hidden,
        // never shown more widely than the merchant chose.
        return array_values(array_intersect($configured, $allowed));
    }
}
