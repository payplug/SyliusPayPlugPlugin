<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Resolver;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\OneyPaymentMethodsResolverDecorator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

final class OneyPaymentMethodsResolverDecoratorTest extends TestCase
{
    private PaymentMethodsResolverInterface&MockObject $decorated;

    private CurrencyContextInterface&MockObject $currencyContext;

    private OneyCheckerInterface&MockObject $oneyChecker;

    private OneyPaymentMethodsResolverDecorator $decorator;

    protected function setUp(): void
    {
        $this->decorated = $this->createMock(PaymentMethodsResolverInterface::class);
        $this->currencyContext = $this->createMock(CurrencyContextInterface::class);
        $this->oneyChecker = $this->createMock(OneyCheckerInterface::class);

        $this->decorator = new OneyPaymentMethodsResolverDecorator(
            $this->decorated,
            $this->currencyContext,
            $this->oneyChecker,
        );
    }

    // -------------------------------------------------------------------------
    // Non-Oney payment methods are passed through unchanged
    // -------------------------------------------------------------------------

    /**
     * The only method in the list uses the standard PayPlug factory (not Oney).
     * Verifies it is returned unchanged — Oney-specific filters are skipped for non-Oney methods.
     */
    public function testGetSupportedMethods_nonOneyMethod_isNotFiltered(): void
    {
        $method = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$method], 1000, 'FR', 'FR', 1);

        $this->decorated->method('getSupportedMethods')->willReturn([$method]);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // Oney disabled at account level → removed
    // -------------------------------------------------------------------------

    /**
     * OneyChecker::isEnabled() returns false (merchant account lacks Oney permission).
     * Verifies the Oney method is removed from the list.
     */
    public function testGetSupportedMethods_oneyDisabled_removesOneyMethod(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod], 1000, 'FR', 'FR', 1);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(false);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // Price ineligible → removed
    // -------------------------------------------------------------------------

    /**
     * Oney is enabled but the order amount (50) is outside Oney's eligible price range.
     * Verifies the Oney method is removed from the list.
     */
    public function testGetSupportedMethods_priceIneligible_removesOneyMethod(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod], 50, 'FR', 'FR', 1);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(true);
        $this->oneyChecker->method('isPriceEligible')->willReturn(false);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // Too many items (> 999) → removed
    // -------------------------------------------------------------------------

    /**
     * Item count is 1000, which exceeds Oney's 999-item limit.
     * Verifies the Oney method is removed from the list.
     */
    public function testGetSupportedMethods_tooManyItems_removesOneyMethod(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod], 1000, 'FR', 'FR', 1000);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(true);
        $this->oneyChecker->method('isPriceEligible')->willReturn(true);
        $this->oneyChecker->method('isNumberOfProductEligible')->willReturn(false);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // Country ineligible (shipping ≠ billing or not FR) → removed
    // -------------------------------------------------------------------------

    /**
     * Shipping country is DE while billing is FR — OneyChecker reports the country as ineligible.
     * Verifies the Oney method is removed from the list.
     */
    public function testGetSupportedMethods_countryIneligible_removesOneyMethod(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod], 1000, 'DE', 'FR', 1);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(true);
        $this->oneyChecker->method('isPriceEligible')->willReturn(true);
        $this->oneyChecker->method('isNumberOfProductEligible')->willReturn(true);
        $this->oneyChecker->method('isCountryEligible')->willReturn(false);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // All conditions met → Oney method kept
    // -------------------------------------------------------------------------

    /**
     * Oney is enabled, price is eligible, item count is 2, and country is eligible.
     * Verifies the Oney method is kept in the result list.
     */
    public function testGetSupportedMethods_allConditionsMet_keepsOneyMethod(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod], 1000, 'FR', 'FR', 2);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(true);
        $this->oneyChecker->method('isPriceEligible')->willReturn(true);
        $this->oneyChecker->method('isNumberOfProductEligible')->willReturn(true);
        $this->oneyChecker->method('isCountryEligible')->willReturn(true);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // Mixed list: non-Oney kept, Oney removed when disabled
    // -------------------------------------------------------------------------

    /**
     * The list contains both an Oney method (disabled) and a standard PayPlug method.
     * Verifies only the Oney method is removed; the PayPlug method is returned intact.
     */
    public function testGetSupportedMethods_mixedList_onlyOneyRemoved(): void
    {
        $oneyMethod = $this->buildPaymentMethod(OneyGatewayFactory::FACTORY_NAME);
        $payplugMethod = $this->buildPaymentMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment([$oneyMethod, $payplugMethod], 1000, 'FR', 'FR', 1);

        $this->decorated->method('getSupportedMethods')->willReturn([$oneyMethod, $payplugMethod]);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');

        $this->oneyChecker->method('isEnabled')->willReturn(false);

        $result = $this->decorator->getSupportedMethods($payment);

        self::assertCount(1, $result);
        self::assertSame($payplugMethod, reset($result));
    }

    // -------------------------------------------------------------------------
    // supports() delegates to decorated
    // -------------------------------------------------------------------------

    /**
     * Calls supports() on the decorator with a Payment object.
     * Verifies the call is forwarded verbatim to the decorated resolver and its result is returned.
     */
    public function testSupports_delegatesToDecorated(): void
    {
        $payment = $this->createMock(Payment::class);
        $this->decorated->method('supports')->with($payment)->willReturn(true);

        self::assertTrue($this->decorator->supports($payment));
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

    private function buildPayment(
        array $methods,
        int $amount,
        string $shippingCountry,
        string $billingCountry,
        int $itemUnitCount,
    ): Payment {
        $shippingAddress = $this->createMock(AddressInterface::class);
        $shippingAddress->method('getCountryCode')->willReturn($shippingCountry);

        $billingAddress = $this->createMock(AddressInterface::class);
        $billingAddress->method('getCountryCode')->willReturn($billingCountry);

        $itemUnits = new ArrayCollection(array_fill(0, $itemUnitCount, new \stdClass()));

        $order = $this->createMock(OrderInterface::class);
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getItemUnits')->willReturn($itemUnits);

        $payment = $this->createMock(Payment::class);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }
}
