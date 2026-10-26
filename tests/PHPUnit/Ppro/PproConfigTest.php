<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfig;
use PHPUnit\Framework\TestCase;

final class PproConfigTest extends TestCase
{
    public function testFromArrayReturnsNullWhenBlockAbsent(): void
    {
        self::assertNull(PproConfig::fromArray(null));
    }

    /**
     * @dataProvider malformedBlocks
     */
    public function testFromArrayReturnsNullWhenBlockMalformed(mixed $stored): void
    {
        self::assertNull(PproConfig::fromArray($stored));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedBlocks(): iterable
    {
        yield 'string block' => ['bancontact'];
        yield 'non-numeric default min' => [['default_amount' => ['min' => 'abc', 'max' => 200000]]];
        yield 'negative configured max' => [['configured_amount' => ['min' => 50, 'max' => -1]]];
        yield 'default amount is a string' => [['default_amount' => '50']];
        yield 'allowed countries is a string' => [['allowed_countries' => 'FR']];
        yield 'allowed countries holds a non-string' => [['allowed_countries' => ['FR', 12]]];
        yield 'configured countries is an int' => [['configured_countries' => 1]];
        yield 'enabled is a string' => [['enabled' => 'yes']];
        yield 'name is an int' => [['name' => 3]];
    }

    public function testPartialBlockIsReadWithDefaults(): void
    {
        $config = PproConfig::fromArray(['configured_amount' => ['min' => 1000, 'max' => null]]);

        self::assertNotNull($config);
        self::assertTrue($config->enabled);
        self::assertNull($config->allowedCountries);
        self::assertNull($config->defaultMin);
        self::assertSame(1000, $config->configuredMin);
        self::assertNull($config->configuredMax);
        self::assertNull($config->configuredCountries);
    }

    public function testHiddenBelowDefaultMin(): void
    {
        self::assertFalse($this->config()->isEligible(49, null));
    }

    public function testHiddenAboveDefaultMax(): void
    {
        self::assertFalse($this->config()->isEligible(200001, null));
    }

    public function testKeptOnBothBoundsInclusive(): void
    {
        self::assertTrue($this->config()->isEligible(50, null));
        self::assertTrue($this->config()->isEligible(200000, null));
    }

    public function testConfiguredRangeRaisesTheFloor(): void
    {
        $config = $this->config(['configured_amount' => ['min' => 5000, 'max' => 200000]]);

        self::assertFalse($config->isEligible(4000, null));
        self::assertTrue($config->isEligible(5000, null));
    }

    public function testConfiguredRangeLowersTheCeiling(): void
    {
        $config = $this->config(['configured_amount' => ['min' => 50, 'max' => 100000]]);

        self::assertFalse($config->isEligible(100001, null));
        self::assertTrue($config->isEligible(100000, null));
    }

    public function testConfiguredRangeOutsideDefaultIsClampedAtRead(): void
    {
        $config = $this->config(['configured_amount' => ['min' => 10, 'max' => 900000]]);

        self::assertFalse($config->isEligible(300000, null));
        self::assertFalse($config->isEligible(20, null));
        self::assertSame(50, $config->effectiveMin());
        self::assertSame(200000, $config->effectiveMax());
    }

    public function testEffectiveMinGreaterThanMaxIsNotEligible(): void
    {
        $config = $this->config(['configured_amount' => ['min' => 150000, 'max' => 100000]]);

        self::assertTrue($config->hasEmptyAmountRange());
        self::assertFalse($config->isEligible(120000, null));
    }

    public function testMissingDefaultSkipsApiBoundsAndUsesConfigured(): void
    {
        $config = $this->config([
            'default_amount' => null,
            'configured_amount' => ['min' => 1000, 'max' => null],
        ]);

        self::assertFalse($config->isEligible(999, null));
        self::assertTrue($config->isEligible(99999999, null));
    }

    public function testNoAmountAtAllIsNeverAmountFiltered(): void
    {
        $config = $this->config(['default_amount' => null, 'configured_amount' => null]);

        self::assertTrue($config->isEligible(0, null));
        self::assertTrue($config->isEligible(99999999, null));
    }

    public function testBillingCountryOutsideAllowedIsHidden(): void
    {
        $config = $this->config(['allowed_countries' => ['BE', 'DE']]);

        self::assertFalse($config->isEligible(1000, 'FR'));
        self::assertTrue($config->isEligible(1000, 'BE'));
    }

    public function testBillingCountryOutsideConfiguredSubsetIsHidden(): void
    {
        $config = $this->config(['allowed_countries' => ['BE', 'DE'], 'configured_countries' => ['BE']]);

        self::assertFalse($config->isEligible(1000, 'DE'));
        self::assertTrue($config->isEligible(1000, 'BE'));
    }

    public function testNullAllowedMeansNoApiRestriction(): void
    {
        self::assertTrue($this->config(['allowed_countries' => null])->isEligible(1000, 'US'));
    }

    public function testNullConfiguredMeansAllEligible(): void
    {
        $config = $this->config(['allowed_countries' => ['BE', 'DE'], 'configured_countries' => null]);

        self::assertTrue($config->isEligible(1000, 'DE'));
        self::assertTrue($config->isEligible(1000, 'BE'));
    }

    public function testConfiguredSubsetAppliesWhenApiHasNoRestriction(): void
    {
        $config = $this->config(['allowed_countries' => null, 'configured_countries' => ['BE']]);

        self::assertFalse($config->isEligible(1000, 'FR'));
        self::assertTrue($config->isEligible(1000, 'BE'));
    }

    public function testNullBillingCountrySkipsTheCountryCheck(): void
    {
        $config = $this->config(['allowed_countries' => ['BE'], 'configured_countries' => ['BE']]);

        self::assertTrue($config->isEligible(1000, null));
    }

    public function testDisabledByApiIsNotEligible(): void
    {
        self::assertFalse($this->config(['enabled' => false])->isEligible(1000, 'BE'));
    }

    public function testNoConfiguredCountryLeftHidesTheMethodEverywhere(): void
    {
        $config = $this->config(['configured_countries' => []]);

        self::assertFalse($config->isEligible(1000, 'BE'));
        self::assertFalse($config->isEligible(1000, null));
    }

    public function testDisabledFlagIsIgnoredWhenNotTrusted(): void
    {
        $config = $this->config(['enabled' => false, 'allowed_countries' => ['BE']]);

        self::assertTrue($config->isEligible(1000, 'BE', false));
        self::assertFalse($config->isEligible(1000, 'FR', false));
        self::assertFalse($config->isEligible(49, 'BE', false));
    }

    public function testIsRefreshedSince(): void
    {
        $threshold = new \DateTimeImmutable('2026-10-20T09:00:00+00:00');

        self::assertTrue($this->config(['refreshed_at' => '2026-10-20T09:00:00+00:00'])->isRefreshedSince($threshold));
        self::assertTrue($this->config(['refreshed_at' => '2026-10-20T11:00:00+02:00'])->isRefreshedSince($threshold));
        self::assertFalse($this->config(['refreshed_at' => '2026-10-20T08:59:59+00:00'])->isRefreshedSince($threshold));
        self::assertFalse($this->config(['refreshed_at' => null])->isRefreshedSince($threshold));
        self::assertFalse($this->config(['refreshed_at' => 'yesterday'])->isRefreshedSince($threshold));
    }

    public function testToArrayRoundTrips(): void
    {
        $stored = $this->stored([
            'allowed_countries' => ['BE', 'DE'],
            'configured_countries' => ['BE'],
            'live' => true,
            'refreshed_at' => '2026-10-20T09:00:00+00:00',
        ]);

        $config = PproConfig::fromArray($stored);

        self::assertNotNull($config);
        self::assertSame($stored, $config->toArray());
    }

    public function testToArrayStoresNullDefaultAmountWhenUnknown(): void
    {
        $config = $this->config(['default_amount' => null]);

        self::assertNull($config->toArray()['default_amount']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): PproConfig
    {
        $config = PproConfig::fromArray($this->stored($overrides));
        self::assertNotNull($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function stored(array $overrides = []): array
    {
        return array_merge([
            'name' => 'bancontact',
            'enabled' => true,
            'allowed_countries' => null,
            'default_amount' => ['min' => 50, 'max' => 200000],
            'configured_amount' => ['min' => 50, 'max' => 200000],
            'configured_countries' => null,
            'live' => null,
            'refreshed_at' => null,
        ], $overrides);
    }
}
