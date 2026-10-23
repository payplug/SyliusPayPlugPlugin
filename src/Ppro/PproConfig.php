<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

/**
 * Read model of the `payment_method` block a PPRO gateway config stores: the PayPlug limits
 * (`allowed_countries`, `default_amount`) and the merchant's narrowing of them
 * (`configured_amount`, `configured_countries`). Amounts are EUR cents. A null country list
 * means "no restriction"; a null amount side is not compared.
 */
final class PproConfig
{
    public const CONFIG_KEY = 'payment_method';

    /**
     * @param list<string>|null $allowedCountries
     * @param list<string>|null $configuredCountries
     */
    public function __construct(
        public readonly ?string $name,
        public readonly bool $enabled,
        public readonly ?array $allowedCountries,
        public readonly ?int $defaultMin,
        public readonly ?int $defaultMax,
        public readonly ?int $configuredMin,
        public readonly ?int $configuredMax,
        public readonly ?array $configuredCountries,
        public readonly ?bool $live = null,
        public readonly ?string $refreshedAt = null,
    ) {
    }

    /**
     * Returns null when nothing is stored or when the stored value does not have the expected
     * shape; never throws.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }

        try {
            return StoredPproConfigReader::read($stored);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Combines freshly fetched PayPlug limits ($api) with the merchant settings of $previous: a
     * configured amount is kept but clamped into the new bounds (an empty side keeps following the
     * PayPlug limit, a range left empty falls back to the PayPlug limits), and configured countries
     * are intersected with the new allowed list (an empty intersection falls back to all countries).
     */
    public static function fromApiLimits(self $api, ?self $previous): self
    {
        [$configuredMin, $configuredMax] = PproSettingsAdjuster::clampAmounts(
            $previous?->configuredMin,
            $previous?->configuredMax,
            $api->defaultMin,
            $api->defaultMax,
        );

        return new self(
            $api->name,
            $api->enabled,
            $api->allowedCountries,
            $api->defaultMin,
            $api->defaultMax,
            $configuredMin,
            $configuredMax,
            PproSettingsAdjuster::intersectCountries($previous?->configuredCountries, $api->allowedCountries),
            $api->live,
            $api->refreshedAt,
        );
    }

    /**
     * Whether a merchant setting held by $previous had to change to fit the limits held here.
     */
    public function adjustsSettingsOf(?self $previous): bool
    {
        if (null === $previous) {
            return false;
        }

        return (null !== $previous->configuredMin && $previous->configuredMin !== $this->configuredMin) ||
            (null !== $previous->configuredMax && $previous->configuredMax !== $this->configuredMax) ||
            (null !== $previous->configuredCountries && $previous->configuredCountries !== $this->configuredCountries);
    }

    /**
     * @param bool $trustDisabledFlag false when the stored `enabled` flag is too old to hide the method
     */
    public function isEligible(int $amount, ?string $billingCountry, bool $trustDisabledFlag = true): bool
    {
        if (($trustDisabledFlag && !$this->enabled) || $this->hasEmptyAmountRange()) {
            return false;
        }

        if (!$this->isAmountInRange($amount)) {
            return false;
        }

        return null === $billingCountry || $this->isCountryEligible($billingCountry);
    }

    /**
     * False when the PayPlug limits were never captured or their timestamp cannot be read.
     */
    public function isRefreshedSince(\DateTimeInterface $threshold): bool
    {
        if (null === $this->refreshedAt) {
            return false;
        }

        $refreshedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $this->refreshedAt);

        return false !== $refreshedAt && $refreshedAt >= $threshold;
    }

    public function effectiveMin(): ?int
    {
        if (null === $this->configuredMin || null === $this->defaultMin) {
            return $this->configuredMin ?? $this->defaultMin;
        }

        return max($this->configuredMin, $this->defaultMin);
    }

    public function effectiveMax(): ?int
    {
        if (null === $this->configuredMax || null === $this->defaultMax) {
            return $this->configuredMax ?? $this->defaultMax;
        }

        return min($this->configuredMax, $this->defaultMax);
    }

    public function hasEmptyAmountRange(): bool
    {
        $min = $this->effectiveMin();
        $max = $this->effectiveMax();

        return null !== $min && null !== $max && $min > $max;
    }

    /**
     * @return array{
     *     name: string|null,
     *     enabled: bool,
     *     allowed_countries: list<string>|null,
     *     default_amount: array{min: int|null, max: int|null}|null,
     *     configured_amount: array{min: int|null, max: int|null},
     *     configured_countries: list<string>|null,
     *     live: bool|null,
     *     refreshed_at: string|null,
     * }
     */
    public function toArray(): array
    {
        $defaultAmount = null === $this->defaultMin && null === $this->defaultMax
            ? null
            : ['min' => $this->defaultMin, 'max' => $this->defaultMax];

        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'allowed_countries' => $this->allowedCountries,
            'default_amount' => $defaultAmount,
            'configured_amount' => ['min' => $this->configuredMin, 'max' => $this->configuredMax],
            'configured_countries' => $this->configuredCountries,
            'live' => $this->live,
            'refreshed_at' => $this->refreshedAt,
        ];
    }

    private function isAmountInRange(int $amount): bool
    {
        $min = $this->effectiveMin();
        $max = $this->effectiveMax();

        return (null === $min || $amount >= $min) && (null === $max || $amount <= $max);
    }

    private function isCountryEligible(string $country): bool
    {
        if (null !== $this->allowedCountries && !\in_array($country, $this->allowedCountries, true)) {
            return false;
        }

        return null === $this->configuredCountries || \in_array($country, $this->configuredCountries, true);
    }
}
