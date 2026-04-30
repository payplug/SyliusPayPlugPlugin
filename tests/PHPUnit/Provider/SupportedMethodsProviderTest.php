<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Provider;

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

    private SupportedMethodsProvider $provider;

    protected function setUp(): void
    {
        $this->currencyContext = $this->createMock(CurrencyContextInterface::class);
        $this->provider = new SupportedMethodsProvider($this->currencyContext);
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

        // PaymentMethod is PayPlug, but we're querying for Bancontact — so it's passed through as-is
        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], BancontactGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 1000);

        // Method stays in list (it doesn't match the target factory, so the currency/amount check never runs)
        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // provide() — currency not authorized → method removed
    // -------------------------------------------------------------------------

    /**
     * The current currency is USD but the method only authorises EUR.
     * Verifies the method is removed from the result list.
     */
    public function testProvide_withUnauthorizedCurrency_removesMethod(): void
    {
        $this->currencyContext->method('getCurrencyCode')->willReturn('USD');

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 1000);

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

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 50);

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

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 2000001);

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

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 1000);

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

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 99);

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

        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        $result = $this->provider->provide([$method], PayPlugGatewayFactory::FACTORY_NAME, $authorizedCurrencies, 2000000);

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

        $payplugMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $bancontactMethod = $this->buildPaymentMethod(BancontactGatewayFactory::FACTORY_NAME);
        $authorizedCurrencies = ['EUR' => ['min_amount' => 99, 'max_amount' => 2000000]];

        // We query for PayPlug factory only; amount is valid
        $result = $this->provider->provide(
            [$payplugMethod, $bancontactMethod],
            PayPlugGatewayFactory::FACTORY_NAME,
            $authorizedCurrencies,
            1000,
        );

        // Bancontact is skipped (different factory), PayPlug stays
        self::assertCount(2, $result); // Bancontact not removed (no filter applied for different factory)
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPaymentMethod(string $factoryName): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }
}
