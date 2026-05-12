<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\SupportedMethodsProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

final class SupportedMethodsProviderTest extends TestCase
{
    private CurrencyContextInterface&MockObject $currencyContext;

    private PayPlugApiClientFactoryInterface&MockObject $clientFactory;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private SupportedMethodsProvider $provider;

    protected function setUp(): void
    {
        $this->currencyContext = $this->createMock(CurrencyContextInterface::class);
        $this->clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);

        $this->clientFactory->method('create')->willReturn($this->apiClient);

        $this->provider = new SupportedMethodsProvider($this->currencyContext, $this->clientFactory);
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
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(30, 2000000));

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
     */
    public function testProvide_withUnauthorizedCurrency_removesMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('USD');
        $this->apiClient->method('getAccount')->willReturn($this->buildAccount(99, 2000000));

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, 1000);

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
    // provide() — payment method specific amounts used over configuration defaults
    // -------------------------------------------------------------------------

    /**
     * When the account JSON has specific min/max for the payment method,
     * those values take precedence over the configuration defaults.
     */
    public function testProvide_usesPaymentMethodSpecificAmounts(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        // Scalapay has tighter limits (500–200000) vs configuration defaults (30–2000000)
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
        $this->apiClient->method('getAccount')->willReturn($account);

        $method = $this->buildPaymentMethod('payplug_scalapay');

        // Amount 400 is above the configuration default min (30) but below Scalapay's min (500)
        $result = $this->provider->provide([$method], 'payplug_scalapay', 400);
        self::assertEmpty($result);

        // Amount 500 is exactly at Scalapay's min — should be kept
        $result2 = $this->provider->provide([$method], 'payplug_scalapay', 500);
        self::assertCount(1, $result2);
    }

    // -------------------------------------------------------------------------
    // provide() — fallback to configuration when payment method has no amounts
    // -------------------------------------------------------------------------

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
                    // no min_amounts / max_amounts
                ],
            ],
        ];
        $this->apiClient->method('getAccount')->willReturn($account);

        $method = $this->buildPaymentMethod('payplug_apple_pay');

        // 30 is at configuration min — should be kept
        $result = $this->provider->provide([$method], 'payplug_apple_pay', 30);
        self::assertCount(1, $result);

        // 29 is below configuration min — should be removed
        $result2 = $this->provider->provide([$method], 'payplug_apple_pay', 29);
        self::assertEmpty($result2);
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

    private function buildPaymentMethod(string $factoryName): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }
}
