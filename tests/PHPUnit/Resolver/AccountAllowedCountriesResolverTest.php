<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Resolver;

use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PHPUnit\Framework\TestCase;

final class AccountAllowedCountriesResolverTest extends TestCase
{
    private AccountAllowedCountriesResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AccountAllowedCountriesResolver();
    }

    public function testReturnsTheAllowedCountriesOfThePaymentMethod(): void
    {
        self::assertSame(['BE', 'DE'], $this->resolver->resolve($this->account(['BE', 'DE']), 'wero'));
    }

    /**
     * @dataProvider unrestrictedAccounts
     */
    public function testReturnsNullWhenThereIsNoRestriction(array $account, ?string $paymentMethodKey): void
    {
        self::assertNull($this->resolver->resolve($account, $paymentMethodKey));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function unrestrictedAccounts(): iterable
    {
        yield 'ALL' => [['payment_methods' => ['wero' => ['allowed_countries' => ['ALL']]]], 'wero'];
        yield 'ALL among codes' => [['payment_methods' => ['wero' => ['allowed_countries' => ['FR', 'ALL']]]], 'wero'];
        yield 'key missing' => [['payment_methods' => ['wero' => ['enabled' => true]]], 'wero'];
        yield 'empty list' => [['payment_methods' => ['wero' => ['allowed_countries' => []]]], 'wero'];
        yield 'entry missing' => [['payment_methods' => []], 'wero'];
        yield 'payment_methods missing' => [[], 'wero'];
        yield 'card gateway' => [['payment_methods' => ['wero' => ['allowed_countries' => ['BE']]]], null];
    }

    /**
     * @dataProvider malformedAccounts
     */
    public function testThrowsOnAMalformedPayload(array $account): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->resolver->resolve($account, 'wero');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function malformedAccounts(): iterable
    {
        yield 'payment_methods is a string' => [['payment_methods' => 'wero']];
        yield 'entry is a string' => [['payment_methods' => ['wero' => 'BE']]];
        yield 'allowed_countries is a string' => [['payment_methods' => ['wero' => ['allowed_countries' => 'BE']]]];
        yield 'non-string code' => [['payment_methods' => ['wero' => ['allowed_countries' => ['BE', 12]]]]];
    }

    /**
     * @param list<string> $allowedCountries
     *
     * @return array<string, mixed>
     */
    private function account(array $allowedCountries): array
    {
        return ['payment_methods' => ['wero' => ['enabled' => true, 'allowed_countries' => $allowedCountries]]];
    }
}
