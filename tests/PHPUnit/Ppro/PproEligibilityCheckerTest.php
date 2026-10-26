<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\Clock\MockClock;

final class PproEligibilityCheckerTest extends TestCase
{
    private const NOW = '2026-10-20T09:00:00+00:00';

    private LoggerInterface&MockObject $logger;

    private PproEligibilityChecker $checker;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->checker = new PproEligibilityChecker($this->logger, new MockClock(self::NOW));
    }

    public function testNoStoredBlockIsUndecidedForEur(): void
    {
        $this->logger->expects(self::never())->method('warning');

        self::assertNull($this->checker->isEligible($this->paymentMethod([]), 1, 'EUR', 'US'));
        self::assertNull($this->checker->isEligible($this->paymentMethod([]), 99999999, 'EUR', null));
    }

    public function testPaymentMethodWithoutGatewayConfigIsUndecided(): void
    {
        self::assertNull($this->checker->isEligible(new PaymentMethod(), 1000, 'EUR', 'FR'));
    }

    public function testMalformedBlockIsUndecidedAndLogged(): void
    {
        $this->logger->expects(self::once())->method('warning');

        $paymentMethod = $this->paymentMethod(['payment_method' => ['default_amount' => ['min' => 'abc']]]);

        self::assertNull($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'FR'));
    }

    /**
     * Merchant settings alone (left by a logout, or saved before the first capture succeeded) carry
     * no PayPlug limits: the caller falls back to the live account check.
     */
    public function testBlockWithoutCapturedLimitsIsUndecided(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => [
            'configured_amount' => ['min' => 1000, 'max' => 5000],
            'configured_countries' => ['BE'],
        ]]);

        self::assertNull($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
    }

    public function testNonEurCurrencyIsHidden(): void
    {
        self::assertFalse($this->checker->isEligible($this->paymentMethod([]), 1000, 'USD', null));
        self::assertFalse($this->checker->isEligible($this->paymentMethod(['payment_method' => $this->block()]), 1000, 'USD', 'BE'));
    }

    public function testDelegatesToPproConfig(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block()]);

        self::assertTrue($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'FR'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 29, 'EUR', 'BE'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 2000001, 'EUR', 'BE'));
    }

    /**
     * The empty range is logged once by the refresher that stores it, not on every checkout render.
     */
    public function testEmptyEffectiveRangeIsHiddenWithoutLogging(): void
    {
        $this->logger->expects(self::never())->method('warning');

        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block([
            'default_amount' => ['min' => 30, 'max' => 1000],
            'configured_amount' => ['min' => 5000, 'max' => 9000],
        ])]);

        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
    }

    public function testRecentDisabledFlagHidesTheMethod(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block([
            'enabled' => false,
            'refreshed_at' => '2026-10-19T09:00:00+00:00',
        ])]);

        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
    }

    /**
     * PayPlug may have activated the method since: a stale disabled flag no longer hides it, and
     * PayPlug decides at payment time. The stored amount and country limits still apply.
     */
    public function testStaleDisabledFlagNoLongerHidesTheMethod(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block([
            'enabled' => false,
            'refreshed_at' => '2026-10-19T08:59:59+00:00',
        ])]);

        self::assertTrue($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'FR'));
    }

    /**
     * Limits fetched in the other environment (TEST vs LIVE) are not the ones the payment method now
     * runs on, for instance when the refresh after a LIVE switch failed: the caller falls back to the
     * live account check until they are refreshed.
     */
    public function testBlockFromTheOtherEnvironmentIsUndecided(): void
    {
        $paymentMethod = $this->paymentMethod(['live' => true, 'payment_method' => $this->block(['live' => false])]);

        self::assertNull($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
    }

    public function testMerchantSettingsAllowEverythingWhenNoneAreStored(): void
    {
        self::assertTrue($this->checker->isAllowedByMerchantSettings($this->paymentMethod([]), 1, 'US'));
        self::assertTrue($this->checker->isAllowedByMerchantSettings(new PaymentMethod(), 1, 'US'));
    }

    public function testMerchantSettingsApplyTheConfiguredRangeAndCountries(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => [
            'configured_amount' => ['min' => 1000, 'max' => 5000],
            'configured_countries' => ['BE'],
        ]]);

        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 999, 'BE'));
        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, 'BE'));
        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 5001, 'BE'));
        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, 'FR'));
        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, null));
    }

    /**
     * Only the merchant's own settings: the PayPlug side comes from the live account check, so stored
     * PayPlug limits (possibly stale, or from the other environment) are not applied again.
     */
    public function testMerchantSettingsIgnoreTheStoredPayPlugLimits(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block([
            'enabled' => false,
            'allowed_countries' => ['DE'],
            'default_amount' => ['min' => 5000, 'max' => 9000],
            'configured_amount' => ['min' => null, 'max' => null],
        ])]);

        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, 'BE'));
    }

    public function testMerchantSettingsWithNoCountryLeftHideTheMethod(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => ['configured_countries' => []]]);

        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, 'BE'));
        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 1000, null));
    }

    public function testMerchantSettingsUseTheLegacyScalapayRange(): void
    {
        $paymentMethod = $this->paymentMethod(['min_amount' => 5000, 'max_amount' => 90000], ScalapayGatewayFactory::FACTORY_NAME);

        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 4999, 'FR'));
        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 5000, 'FR'));
        self::assertFalse($this->checker->isAllowedByMerchantSettings($paymentMethod, 90001, 'FR'));
    }

    public function testLegacyRangeKeysAreIgnoredForOtherFactories(): void
    {
        $paymentMethod = $this->paymentMethod(['min_amount' => 5000, 'max_amount' => 90000]);

        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 1, 'FR'));
    }

    public function testMalformedMerchantSettingsAllowEverything(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => ['configured_amount' => ['min' => 'abc']]]);

        self::assertTrue($this->checker->isAllowedByMerchantSettings($paymentMethod, 1, 'US'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'name' => 'wero',
            'enabled' => true,
            'allowed_countries' => ['BE', 'DE'],
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => ['min' => 30, 'max' => 2000000],
            'configured_countries' => null,
            'live' => false,
            'refreshed_at' => self::NOW,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(array $config, string $factoryName = WeroGatewayFactory::FACTORY_NAME): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('wero');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
