<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Provider;

use Payplug\Exception\ConnectionException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PayPlug\SyliusPayPlugPlugin\Provider\SupportedMethodsProvider;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Clock\MockClock;

final class SupportedMethodsProviderTest extends TestCase
{
    private const NOW = '2026-10-20T09:00:00+00:00';

    private CurrencyContextInterface&MockObject $currencyContext;

    private PayPlugApiClientFactoryInterface&MockObject $clientFactory;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private LoggerInterface&MockObject $logger;

    private SupportedMethodsProvider $provider;

    /** @var \SplObjectStorage<object, PayPlugApiClientInterface> */
    private \SplObjectStorage $clientsByPaymentMethod;

    protected function setUp(): void
    {
        $this->currencyContext = $this->createMock(CurrencyContextInterface::class);
        $this->clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);

        // Every payment method resolves to the shared client unless a test gives it one of its own
        // through assignAccount(), which is how a second PayPlug account is modelled.
        $this->clientsByPaymentMethod = new \SplObjectStorage();
        $this->clientFactory->method('createForPaymentMethod')->willReturnCallback(
            fn (object $paymentMethod): PayPlugApiClientInterface => $this->clientsByPaymentMethod->contains($paymentMethod)
                ? $this->clientsByPaymentMethod[$paymentMethod]
                : $this->apiClient,
        );

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provider = new SupportedMethodsProvider(
            $this->currencyContext,
            $this->clientFactory,
            new AccountAmountRangeResolver(),
            $this->logger,
            new PproEligibilityChecker(new NullLogger(), new MockClock(self::NOW)),
            new AccountAllowedCountriesResolver(),
        );
    }

    // -------------------------------------------------------------------------
    // provide() — factory name filter
    // -------------------------------------------------------------------------

    /**
     * The payment method's factory name (PayPlug) differs from the queried factory (Bancontact).
     * Verifies the method is passed through unchanged — currency/amount checks only apply to matching factories.
     */
    public function testProvide_withDifferentFactory_doesNotFilter(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        // getAccount() must never be called: the PayPlug method never matches the Bancontact factory,
        // so the loop always hits `continue` before reaching the lazy-loading lines.
        $this->apiClient->expects(self::never())->method('getAccount');

        // PaymentMethod is PayPlug, but we're querying for Bancontact — so it's passed through as-is
        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 1000);

        // Method stays in list (it doesn't match the target factory, so the currency/amount check never runs)
        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // provide() — currency not authorized → method removed
    // -------------------------------------------------------------------------

    /**
     * The current currency is USD but the method only authorizes EUR.
     * Verifies the method is removed from the result list.
     *
     * No $paymentCurrencyCode is passed, so this also covers the documented fallback to
     * CurrencyContextInterface for a payment carrying no currency of its own.
     */
    public function testProvide_withUnauthorizedCurrency_removesMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('USD');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 1000);

        self::assertEmpty($result);
    }

    /**
     * The payment's own currency decides, not the one being displayed. Here the shopper browses in
     * EUR (authorized) while the order was placed in USD (not authorized) — the amount is USD, so
     * the method must go. Reading the display currency instead would keep a method whose limits
     * were never checked against the amount's actual currency.
     */
    public function testProvide_withPaymentCurrencyUnauthorized_ignoresDisplayCurrency(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide(
            [$method],
            PayPlugGatewayFactory::FACTORY_NAME,
            1000,
            paymentCurrencyCode: 'USD',
        );

        self::assertEmpty($result);
    }

    /**
     * The mirror case, and the one that was mis-filtering before: the order is in EUR (authorized,
     * amount within bounds) while the shopper has switched the display to USD. The method must be
     * kept — previously the USD display currency was compared against a EUR-only account and hid a
     * payment method that would have been charged in EUR.
     */
    public function testProvide_withPaymentCurrencyAuthorized_keepsMethodDespiteDisplayCurrency(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('USD');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide(
            [$method],
            PayPlugGatewayFactory::FACTORY_NAME,
            1000,
            paymentCurrencyCode: 'EUR',
        );

        self::assertCount(1, $result);
    }

    /**
     * Same unauthorized-currency setup, but the `payplug` method has Hosted Fields selected.
     * UHF is exempt from the currency gate because the Retail `/account` payload does not know a
     * UHF account's currencies (see the comment in SupportedMethodsProvider), so the method must
     * survive — and, having no advertised limits for USD, must not be amount-filtered either
     * despite 1000 sitting outside the EUR 99..2000000 range that the payload does advertise.
     */
    public function testProvide_withUnauthorizedCurrencyAndHostedFields_keepsMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('USD');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
        ]);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 1000);

        self::assertCount(1, $result);
    }

    /**
     * Hosted Fields is exempt from the currency gate, not from the amount limits: when the active
     * currency *is* advertised, its min/max still apply.
     */
    public function testProvide_withAuthorizedCurrencyAndHostedFields_stillAppliesAmountLimits(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, [
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
        ]);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 50);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // provide() — amount below min_amount → method removed
    // -------------------------------------------------------------------------

    /**
     * The order amount (50) is below the method's configured min_amount (99).
     * Verifies the method is removed from the result list.
     */
    public function testProvide_withAmountBelowMin_removesMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 50);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // provide() — amount above max_amount → method removed
    // -------------------------------------------------------------------------

    /**
     * The order amount (2 000 001) exceeds the method's configured max_amount (2 000 000).
     * Verifies the method is removed from the result list.
     */
    public function testProvide_withAmountAboveMax_removesMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 2000001);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // provide() — valid currency and amount → method kept
    // -------------------------------------------------------------------------

    /**
     * Currency is EUR and amount (1000) is within [99, 2 000 000].
     * Verifies the method is kept and returned as the sole element.
     */
    public function testProvide_withValidCurrencyAndAmount_keepsMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 1000);

        self::assertCount(1, $result);
        self::assertSame($method, reset($result));
    }

    // -------------------------------------------------------------------------
    // provide() — amount at exact min boundary → kept
    // -------------------------------------------------------------------------

    /**
     * Amount equals exactly the min_amount boundary (99 == 99).
     * Verifies the method is kept (inclusive lower bound).
     */
    public function testProvide_withAmountAtMinBoundary_keepsMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 99);

        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // provide() — amount at exact max boundary → kept
    // -------------------------------------------------------------------------

    /**
     * Amount equals exactly the max_amount boundary (2 000 000 == 2 000 000).
     * Verifies the method is kept (inclusive upper bound).
     */
    public function testProvide_withAmountAtMaxBoundary_keepsMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 2000000);

        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // provide() — mixed list: only matching factory + valid amount/currency kept
    // -------------------------------------------------------------------------

    /**
     * List contains a PayPlug method (matching factory) and a Bancontact method (different factory).
     * Verifies the PayPlug method is kept and the Bancontact method is also kept (no filter for its factory).
     */
    public function testProvide_withMixedList_filtersCorrectly(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $payplugMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $bancontactMethod = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME);

        // We query for PayPlug factory only; amount is valid
        $result = $this->provider->provide(
            [$payplugMethod, $bancontactMethod],
            PayPlugGatewayFactory::FACTORY_NAME,
            1000,
        );

        // Bancontact is skipped (different factory), PayPlug stays
        self::assertCount(2, $result); // Bancontact not removed (no filter applied for different factory)
    }

    // -------------------------------------------------------------------------
    // provide() — PPRO methods read their stored configuration, the account only until it is stored
    // -------------------------------------------------------------------------

    public function testPproMethodReadsStoredConfigAndNeverCallsTheApi(): void
    {
        $this->clientFactory->expects(self::never())->method('createForPaymentMethod');

        $method = $this->buildPaymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(['allowed_countries' => ['BE', 'DE']]),
        ]);

        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'FR'));
        self::assertCount(1, $this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'BE'));
    }

    /**
     * Until the PayPlug limits are first stored (right after the upgrade, or after a logout), the
     * method is filtered live from the account, as it was before the limits were stored.
     */
    public function testPproMethodWithoutStoredConfigFallsBackToTheAccount(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('wero', ['BE', 'DE']));

        $method = $this->buildPaymentMethod(WeroGatewayFactory::FACTORY_NAME, []);

        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'FR'));
        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 29, 'EUR', 'BE'));
        self::assertCount(1, $this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'BE'));
    }

    public function testPproMethodWithMerchantSettingsOnlyFallsBackToTheAccount(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('bancontact', ['BE']));

        $method = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME, [
            'payment_method' => ['configured_amount' => ['min' => 1000, 'max' => null], 'configured_countries' => ['BE']],
        ]);

        self::assertEmpty($this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'FR'));
    }

    /**
     * The fallback is the account check plus the merchant's own settings, which the stored block
     * keeps without PayPlug limits (saved before the first refresh, or left by a logout).
     */
    public function testPproFallbackAppliesTheStoredMerchantSettings(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('wero', ['BE', 'DE']));

        $method = $this->buildPaymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => ['configured_amount' => ['min' => 1000, 'max' => null], 'configured_countries' => ['BE']],
        ]);

        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 999, 'EUR', 'BE'));
        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'DE'));
        self::assertCount(1, $this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'BE'));
    }

    /**
     * Until the first refresh carries it over, the Scalapay range set before the upgrade still
     * narrows the checkout, as it did before.
     */
    public function testScalapayFallbackAppliesTheLegacyRange(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(500, 200000));

        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, ['min_amount' => 5000, 'max_amount' => 90000]);

        self::assertEmpty($this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 4999, 'EUR'));
        self::assertCount(1, $this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 5000, 'EUR'));
        self::assertEmpty($this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 90001, 'EUR'));
    }

    public function testPproBlockFromTheOtherEnvironmentFallsBackToTheAccount(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('wero', ['BE']));

        $method = $this->buildPaymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'live' => true,
            'payment_method' => $this->storedBlock(['live' => false, 'allowed_countries' => ['FR']]),
        ]);

        self::assertCount(1, $this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'BE'));
        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'FR'));
    }

    public function testPproMethodWithoutStoredConfigIsKeptWhenTheAccountCannotBeRead(): void
    {
        $this->apiClient->method('getAccount')->willThrowException(new ConnectionException('timeout'));

        $method = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME, []);

        self::assertCount(1, $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 1, 'EUR', 'US'));
    }

    public function testPproMethodWithNonEurPaymentIsRemoved(): void
    {
        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(),
        ]);

        self::assertEmpty($this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 1000, 'USD'));
    }

    public function testPproMethodFallsBackToTheDisplayCurrencyWhenThePaymentHasNone(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(),
        ]);

        self::assertCount(1, $this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 1000));
    }

    public function testPproAllowedCountriesNullKeepsAnyBillingCountry(): void
    {
        $method = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(['allowed_countries' => null]),
        ]);

        self::assertCount(1, $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'US'));
    }

    public function testPproNullBillingCountryKeepsMethod(): void
    {
        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(['allowed_countries' => ['FR', 'DE']]),
        ]);

        self::assertCount(1, $this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 1000, 'EUR', null));
    }

    public function testPproMerchantCountrySubsetIsApplied(): void
    {
        $method = $this->buildPaymentMethod(WeroGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(['allowed_countries' => ['BE', 'DE'], 'configured_countries' => ['BE']]),
        ]);

        self::assertEmpty($this->provider->provide([$method], WeroGatewayFactory::FACTORY_NAME, 1000, 'EUR', 'DE'));
    }

    /**
     * The merchant range is no longer a Scalapay-only override: every PPRO gateway honours it.
     */
    public function testBancontactHonoursMerchantConfiguredAmount(): void
    {
        $method = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock(['configured_amount' => ['min' => 1000, 'max' => 100000]]),
        ]);

        self::assertEmpty($this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 999, 'EUR'));
        self::assertCount(1, $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 1000, 'EUR'));
        self::assertCount(1, $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 100000, 'EUR'));
        self::assertEmpty($this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, 100001, 'EUR'));
    }

    public function testPproApiBoundsApplyWithoutMerchantRange(): void
    {
        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'payment_method' => $this->storedBlock([
                'default_amount' => ['min' => 500, 'max' => 200000],
                'configured_amount' => ['min' => null, 'max' => null],
            ]),
        ]);

        self::assertEmpty($this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 499, 'EUR'));
        self::assertCount(1, $this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 500, 'EUR'));
    }

    public function testScalapayLegacyMinMaxKeysAreIgnored(): void
    {
        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'min_amount' => 90000,
            'max_amount' => 'nonsense',
            'payment_method' => $this->storedBlock(),
        ]);

        self::assertCount(1, $this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 1000, 'EUR'));
    }

    public function testPproMalformedStoredBlockFallsBackToTheAccount(): void
    {
        $this->apiClient->expects(self::once())->method('getAccount')->willReturn($this->buildAccount(500, 200000));

        $method = $this->buildPaymentMethod(ScalapayGatewayFactory::FACTORY_NAME, [
            'payment_method' => ['default_amount' => ['min' => '500']],
        ]);

        self::assertEmpty($this->provider->provide([$method], ScalapayGatewayFactory::FACTORY_NAME, 1, 'EUR'));
    }

    // -------------------------------------------------------------------------
    // provide() — non-PPRO gateways keep the live gate, guarded
    // -------------------------------------------------------------------------

    /**
     * When the account JSON has specific min/max for the payment method,
     * those values take precedence over the configuration defaults.
     */
    public function testProvide_usesPaymentMethodSpecificAmounts(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'apple_pay' => [
                    'min_amounts' => ['EUR' => 500],
                    'max_amounts' => ['EUR' => 200000],
                ],
            ],
        ];
        $this->apiClient->method('getAccount')->willReturn($account);

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertEmpty($this->provider->provide([$method], 'payplug_apple_pay', 400));
        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 500));
    }

    /**
     * When the payment method entry in the API has no min/max amounts (e.g. Apple Pay),
     * the configuration defaults are used.
     */
    public function testProvide_fallsBackToConfigurationAmounts(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $account = [
            'configuration' => [
                'min_amounts' => ['EUR' => 30],
                'max_amounts' => ['EUR' => 2000000],
            ],
            'payment_methods' => [
                'apple_pay' => [
                    'enabled' => true,
                ],
            ],
        ];
        $this->apiClient->method('getAccount')->willReturn($account);

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 30));
        self::assertEmpty($this->provider->provide([$method], 'payplug_apple_pay', 29));
    }

    /**
     * The min_amount/max_amount keys are not read for any gateway: the card gateway carrying them
     * stays on the raw API bounds.
     */
    public function testProvide_merchantConfiguredAmountsIgnoredForNonPproGateway(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, ['min_amount' => 1000, 'max_amount' => 300]);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 150000);
        self::assertCount(1, $result);
    }

    public function testNonPproGatewayIsKeptWhenGetAccountThrows(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->apiClient->method('getAccount')->willThrowException(new ConnectionException('timeout'));
        $this->logger->expects(self::once())->method('warning');

        $method = $this->buildPaymentMethod('payplug_american_express');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_american_express', 1000));
    }

    public function testNonPproGatewayIsKeptWhenFactoryThrowsGatewayConfigurationException(): void
    {
        $clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $clientFactory->method('createForPaymentMethod')->willThrowException(new GatewayConfigurationException('no client configured'));
        $this->logger->expects(self::once())->method('warning');
        $provider = new SupportedMethodsProvider(
            $this->currencyContext,
            $clientFactory,
            new AccountAmountRangeResolver(),
            $this->logger,
            new PproEligibilityChecker($this->logger, new MockClock(self::NOW)),
            new AccountAllowedCountriesResolver(),
        );

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR'));
    }

    public function testNonPproGatewayIsKeptWhenThePayloadIsMalformed(): void
    {
        $this->apiClient->method('getAccount')->willReturn(['configuration' => ['min_amounts' => ['EUR' => '30']]]);
        $this->logger->expects(self::once())->method('warning');

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR'));
    }

    // -------------------------------------------------------------------------
    // provide() — non-PPRO gateways keep the API allowed_countries filter
    // -------------------------------------------------------------------------

    public function testNonPproBillingCountryInAllowedCountriesKeepsMethod(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('apple_pay', ['FR', 'DE']));

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR', 'FR'));
    }

    public function testNonPproBillingCountryOutsideAllowedCountriesRemovesMethod(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('american_express', ['FR', 'DE']));

        $method = $this->buildPaymentMethod('payplug_american_express');

        self::assertEmpty($this->provider->provide([$method], 'payplug_american_express', 1000, 'EUR', 'US'));
    }

    public function testNonPproAllowedCountriesAllKeepsAnyBillingCountry(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('apple_pay', ['ALL']));

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR', 'US'));
    }

    public function testNonPproWithoutAllowedCountriesKeepsAnyBillingCountry(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR', 'US'));
    }

    public function testNonPproNullBillingCountryKeepsMethod(): void
    {
        $this->apiClient->method('getAccount')->willReturn($this->buildAccountWithCountries('apple_pay', ['FR']));

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR', null));
    }

    public function testNonPproMalformedAllowedCountriesKeepsMethodAndLogs(): void
    {
        $account = $this->buildAccount(99, 2000000);
        $account['payment_methods'] = ['apple_pay' => ['allowed_countries' => 'FR']];
        $this->apiClient->method('getAccount')->willReturn($account);
        $this->logger->expects(self::once())->method('warning');

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        self::assertCount(1, $this->provider->provide([$method], 'payplug_apple_pay', 1000, 'EUR', 'US'));
    }

    public function testNonPproMethodsOnDifferentAccountsUseTheirOwnAllowedCountries(): void
    {
        $frMethod = $this->buildPaymentMethod('payplug_apple_pay', configId: 1);
        $deMethod = $this->buildPaymentMethod('payplug_apple_pay', configId: 2);

        $this->assignAccount($frMethod, $this->buildAccountWithCountries('apple_pay', ['FR']));
        $this->assignAccount($deMethod, $this->buildAccountWithCountries('apple_pay', ['DE']));

        $result = $this->provider->provide([$frMethod, $deMethod], 'payplug_apple_pay', 1000, 'EUR', 'FR');

        self::assertCount(1, $result);
        self::assertSame($frMethod, reset($result));
    }

    /**
     * A failed read is memoized like a successful one: the API is not hammered once per method.
     */
    public function testFailedAccountReadIsNotRetriedForTheSameGatewayConfig(): void
    {
        $gatewayConfig = $this->buildGatewayConfig(PayPlugGatewayFactory::FACTORY_NAME, [], 1);
        $first = $this->buildPaymentMethodFor($gatewayConfig);
        $second = $this->buildPaymentMethodFor($gatewayConfig);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $client->expects(self::once())->method('getAccount')->willThrowException(new ConnectionException('timeout'));
        $this->clientsByPaymentMethod[$first] = $client;
        $this->clientsByPaymentMethod[$second] = $client;

        self::assertCount(2, $this->provider->provide([$first, $second], PayPlugGatewayFactory::FACTORY_NAME, 1000, 'EUR'));
    }

    // -------------------------------------------------------------------------
    // provide() — one account per gateway config, not one per call
    // -------------------------------------------------------------------------

    /**
     * Two enabled methods of the same factory sitting on different PayPlug accounts: the account
     * resolved for the first must not decide the fate of the second. Here the first authorizes EUR
     * and the second only USD, so an EUR checkout keeps the first and drops the second — sharing
     * one lookup across the loop kept both.
     */
    public function testProvide_withMethodsOnDifferentAccounts_filtersEachAgainstItsOwnCurrencies(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $eurMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, configId: 1);
        $usdMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME, configId: 2);

        $this->assignAccount($eurMethod, $this->buildAccount(99, 2000000));
        $this->assignAccount($usdMethod, [
            'configuration' => ['min_amounts' => ['USD' => 99], 'max_amounts' => ['USD' => 2000000]],
            'payment_methods' => [],
        ]);

        $result = $this->provider->provide([$eurMethod, $usdMethod], PayPlugGatewayFactory::FACTORY_NAME, 1000);

        self::assertCount(1, $result);
        self::assertSame($eurMethod, reset($result));
    }

    /**
     * Same split, for the billing-country gate: each PPRO method is checked against its own stored
     * allowed countries.
     */
    public function testProvide_withMethodsOnDifferentAccounts_filtersEachAgainstItsOwnAllowedCountries(): void
    {
        $frMethod = $this->buildPaymentMethod('payplug_scalapay', ['payment_method' => $this->storedBlock(['allowed_countries' => ['FR']])], 1);
        $deMethod = $this->buildPaymentMethod('payplug_scalapay', ['payment_method' => $this->storedBlock(['allowed_countries' => ['DE']])], 2);

        $result = $this->provider->provide([$frMethod, $deMethod], 'payplug_scalapay', 1000, 'EUR', 'FR');

        self::assertCount(1, $result);
        self::assertSame($frMethod, reset($result));
    }

    /**
     * The per-account lookup must stay memoized: two methods sharing one gateway config hit the
     * `/account` endpoint once between them, not once each.
     */
    public function testProvide_withMethodsSharingOneGatewayConfig_readsThatAccountOnce(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $gatewayConfig = $this->buildGatewayConfig(PayPlugGatewayFactory::FACTORY_NAME, [], 1);
        $first = $this->buildPaymentMethodFor($gatewayConfig);
        $second = $this->buildPaymentMethodFor($gatewayConfig);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $client->expects(self::once())->method('getAccount')->willReturn($this->buildAccount(99, 2000000));
        $this->clientsByPaymentMethod[$first] = $client;
        $this->clientsByPaymentMethod[$second] = $client;

        $result = $this->provider->provide([$first, $second], PayPlugGatewayFactory::FACTORY_NAME, 1000);

        self::assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAccount(int $minAmount, int $maxAmount): array
    {
        return [
            'configuration' => [
                'min_amounts' => ['EUR' => $minAmount],
                'max_amounts' => ['EUR' => $maxAmount],
            ],
            'payment_methods' => [],
        ];
    }

    /**
     * @param list<string> $allowedCountries
     *
     * @return array<string, mixed>
     */
    private function buildAccountWithCountries(string $paymentMethodKey, array $allowedCountries): array
    {
        return [
            'configuration' => ['min_amounts' => ['EUR' => 30], 'max_amounts' => ['EUR' => 2000000]],
            'payment_methods' => [$paymentMethodKey => ['enabled' => true, 'allowed_countries' => $allowedCountries]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function storedBlock(array $overrides = []): array
    {
        return array_merge([
            'name' => 'wero',
            'enabled' => true,
            'allowed_countries' => null,
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => ['min' => 30, 'max' => 2000000],
            'configured_countries' => null,
            'live' => false,
            'refreshed_at' => self::NOW,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $config Persisted gateway config; defaults to empty, which is
     *                                     neither integrated_payment nor hosted_fields.
     */
    private function buildPaymentMethod(
        string $factoryName,
        array $config = [],
        int|string|null $configId = null,
    ): PaymentMethodInterface {
        return $this->buildPaymentMethodFor($this->buildGatewayConfig($factoryName, $config, $configId));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildGatewayConfig(
        string $factoryName,
        array $config = [],
        int|string|null $configId = null,
    ): GatewayConfigInterface {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn($config);
        $gatewayConfig->method('getId')->willReturn($configId);

        return $gatewayConfig;
    }

    private function buildPaymentMethodFor(GatewayConfigInterface $gatewayConfig): PaymentMethodInterface
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }

    /**
     * Puts $paymentMethod on a PayPlug account of its own, as a second configured gateway would be.
     *
     * @param array<string, mixed> $account
     */
    private function assignAccount(PaymentMethodInterface $paymentMethod, array $account): void
    {
        $client = $this->createMock(PayPlugApiClientInterface::class);
        $client->method('getAccount')->willReturn($account);

        $this->clientsByPaymentMethod[$paymentMethod] = $client;
    }
}
