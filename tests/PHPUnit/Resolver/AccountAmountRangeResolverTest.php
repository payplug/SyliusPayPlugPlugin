<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Resolver;

use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use PHPUnit\Framework\TestCase;

final class AccountAmountRangeResolverTest extends TestCase
{
    private AccountAmountRangeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AccountAmountRangeResolver();
    }

    public function testResolve_withoutPaymentMethodKey_usesConfigurationDefaults(): void
    {
        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 100, 'USD' => 200],
                'max_amounts' => ['EUR' => 100000, 'USD' => 200000],
            ],
        ];

        $result = $this->resolver->resolve($account, null);

        self::assertSame([
            'EUR' => ['min_amount' => 100, 'max_amount' => 100000],
            'USD' => ['min_amount' => 200, 'max_amount' => 200000],
        ], $result);
    }

    public function testResolve_withPaymentMethodOverride_usesOverrideInsteadOfDefaults(): void
    {
        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'scalapay' => [
                    'min_amounts' => ['EUR' => 500],
                    'max_amounts' => ['EUR' => 200000],
                ],
            ],
        ];

        $result = $this->resolver->resolve($account, 'scalapay');

        self::assertSame(['EUR' => ['min_amount' => 500, 'max_amount' => 200000]], $result);
    }

    public function testResolve_withPaymentMethodKeyButNoOverride_fallsBackToConfigurationDefaults(): void
    {
        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'apple_pay' => [
                    'enabled' => true,
                    // no min_amounts / max_amounts
                ],
            ],
        ];

        $result = $this->resolver->resolve($account, 'apple_pay');

        self::assertSame(['EUR' => ['min_amount' => 30, 'max_amount' => 2000000]], $result);
    }

    /**
     * The per-payment-method override is present but the wrong shape (a string, not an array).
     * Verifies this degrades gracefully to the configuration defaults instead of blowing up on
     * a malformed API response — this is the divergence the two original, independent
     * implementations of this parsing logic used to disagree on.
     */
    public function testResolve_withMalformedOverride_fallsBackToConfigurationDefaults(): void
    {
        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'scalapay' => [
                    'min_amounts' => 'not-an-array',
                    'max_amounts' => 'not-an-array',
                ],
            ],
        ];

        $result = $this->resolver->resolve($account, 'scalapay');

        self::assertSame(['EUR' => ['min_amount' => 30, 'max_amount' => 2000000]], $result);
    }

    public function testResolve_currencyMissingFromMaxAmounts_isExcluded(): void
    {
        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 100, 'USD' => 200],
                'max_amounts' => ['EUR' => 100000],
            ],
        ];

        $result = $this->resolver->resolve($account, null);

        self::assertSame(['EUR' => ['min_amount' => 100, 'max_amount' => 100000]], $result);
    }

    public function testResolve_missingConfiguration_returnsEmptyArray(): void
    {
        self::assertSame([], $this->resolver->resolve([], null));
    }
}
