<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfig;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationValidator;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PproConfigurationValidatorTest extends TestCase
{
    private PproConfigurationValidator $validator;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => implode('|', [$domain, $id, ...array_values($parameters)]),
        );

        $this->validator = new PproConfigurationValidator($translator, new MoneyFormatter());
    }

    public function testWithinApiBoundsHasNoViolation(): void
    {
        self::assertSame([], $this->validator->violations($this->stored(), 1000, 100000, ['FR']));
    }

    public function testMinBelowApiMinReportsBothBounds(): void
    {
        self::assertSame(
            ['ppro_min_amount' => ['validators|payplug_sylius_payplug_plugin.ppro.min_below_api_min|€5.00|€2,000.00']],
            $this->validator->violations($this->stored(), 100, null, null),
        );
    }

    public function testMaxAboveApiMaxReportsBothBounds(): void
    {
        self::assertSame(
            ['ppro_max_amount' => ['validators|payplug_sylius_payplug_plugin.ppro.max_above_api_max|€5.00|€2,000.00']],
            $this->validator->violations($this->stored(), null, 300000, null),
        );
    }

    public function testMinGreaterThanMax(): void
    {
        self::assertSame(
            ['ppro_min_amount' => ['validators|payplug_sylius_payplug_plugin.ppro.min_greater_than_max']],
            $this->validator->violations($this->stored(), 5000, 1000, null),
        );
    }

    /**
     * Only the max is set, below the API min the empty min falls back to: the effective range is
     * inverted although neither value alone is out of its own bound.
     */
    public function testOnlyMaxConfiguredBelowEffectiveMinIsMinGreaterThanMax(): void
    {
        $violations = $this->validator->violations($this->stored(), null, 300, null);

        self::assertSame(['validators|payplug_sylius_payplug_plugin.ppro.min_greater_than_max'], $violations['ppro_min_amount'] ?? null);
    }

    public function testEmptyAmountsFallBackToDefaultsWithoutError(): void
    {
        self::assertSame([], $this->validator->violations($this->stored(), null, null, null));
    }

    public function testNoCountrySelectedIsRefused(): void
    {
        self::assertSame(
            ['ppro_countries' => ['validators|payplug_sylius_payplug_plugin.ppro.no_country']],
            $this->validator->violations($this->stored(), null, null, []),
        );
    }

    public function testNullCountriesMeansAllAndIsAccepted(): void
    {
        self::assertSame([], $this->validator->violations($this->stored(['allowed_countries' => ['BE', 'DE']]), null, null, null));
    }

    public function testCountryOutsideAllowedListsTheCodes(): void
    {
        self::assertSame(
            ['ppro_countries' => ['validators|payplug_sylius_payplug_plugin.ppro.country_not_allowed|FR, IT']],
            $this->validator->violations($this->stored(['allowed_countries' => ['BE', 'DE']]), null, null, ['BE', 'FR', 'IT']),
        );
    }

    public function testAnyCountryIsAcceptedWhenPayPlugSetsNoRestriction(): void
    {
        self::assertSame([], $this->validator->violations($this->stored(['allowed_countries' => null]), null, null, ['US']));
    }

    public function testUnknownStoredBlockSkipsBoundsButKeepsMinMaxAndCountryRules(): void
    {
        self::assertSame([], $this->validator->violations(null, 1, 999999999, ['US']));
        self::assertSame(
            [
                'ppro_min_amount' => ['validators|payplug_sylius_payplug_plugin.ppro.min_greater_than_max'],
                'ppro_countries' => ['validators|payplug_sylius_payplug_plugin.ppro.no_country'],
            ],
            $this->validator->violations(null, 5000, 1000, []),
        );
    }

    public function testUnknownDefaultAmountSkipsBounds(): void
    {
        self::assertSame([], $this->validator->violations($this->stored(['default_amount' => null]), 1, 999999999, null));
    }

    public function testEveryRuleIsReportedTogether(): void
    {
        $violations = $this->validator->violations($this->stored(['allowed_countries' => ['BE']]), 100, 300000, []);

        self::assertCount(1, $violations['ppro_min_amount']);
        self::assertCount(1, $violations['ppro_max_amount']);
        self::assertCount(1, $violations['ppro_countries']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function stored(array $overrides = []): PproConfig
    {
        $config = PproConfig::fromArray(array_merge([
            'name' => 'scalapay',
            'enabled' => true,
            'allowed_countries' => null,
            'default_amount' => ['min' => 500, 'max' => 200000],
            'configured_amount' => ['min' => 500, 'max' => 200000],
            'configured_countries' => null,
        ], $overrides));
        self::assertNotNull($config);

        return $config;
    }
}
