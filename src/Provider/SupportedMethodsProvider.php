<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Webmozart\Assert\Assert;

final class SupportedMethodsProvider
{
    public function __construct(
        private CurrencyContextInterface $currencyContext,
        private PayPlugApiClientFactoryInterface $clientFactory,
    ) {
    }

    /**
     * $paymentCurrencyCode must be the currency $paymentAmount is denominated in — i.e. the
     * payment's own, which Sylius copies from the order (OrderPaymentProcessor sets both amount and
     * currency from $order). Passing it in rather than reading CurrencyContextInterface here is
     * what keeps the two halves of every comparison below in the same currency: the context holds
     * the currency being *displayed* right now, which on a multi-currency channel need not be the
     * one the order was placed in — and comparing an amount against another currency's min/max
     * silently filters the wrong methods.
     *
     * The context remains the fallback for a payment with no currency of its own, which the Sylius
     * contract permits (PaymentInterface::getCurrencyCode() is nullable) even though the checkout
     * flow always sets one.
     */
    public function provide(
        array $supportedMethods,
        string $factoryName,
        int $paymentAmount,
        ?string $paymentCurrencyCode = null,
        ?string $billingCountryCode = null,
    ): array {
        $activeCurrencyCode = $paymentCurrencyCode ?? $this->currencyContext->getCurrencyCode();
        $authorizedCurrencies = null;
        $allowedCountries = null;

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if ($factoryName !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            $authorizedCurrencies ??= $this->resolveAuthorizedCurrencies($factoryName);
            $allowedCountries ??= $this->resolveAllowedCountries($factoryName);

            if ($billingCountryCode !== null && $allowedCountries !== [] && !\in_array($billingCountryCode, $allowedCountries, true)) {
                unset($supportedMethods[$key]);

                continue;
            }

            if (!\array_key_exists($activeCurrencyCode, $authorizedCurrencies)) {
                // Unified Hosted Fields is exempt from the currency gate, pending a source of truth
                // that actually knows a UHF account's currencies. The Retail `/account` payload only
                // advertises the legacy acquiring setup (`configuration.min_amounts` /
                // `max_amounts`), which is demonstrably wrong for a UHF account: account 1461487
                // reports `currencies: ['EUR']` while a USD payment on that same account completed
                // on staging (schemeTransactionId 6a9ad8eb905d3). Hiding a method that works is the
                // worse failure, so UHF is kept and left to the API to accept or refuse.
                //
                // Every other gateway keeps the gate: Bancontact, Scalapay, Apple Pay, American
                // Express and Wero are EUR-only, and `payplug` in integrated_payment mode is served
                // by the very legacy setup this payload does describe correctly.
                if (!PayPlugGatewayFactory::isHostedFieldsConfig($gatewayConfig)) {
                    unset($supportedMethods[$key]);
                }

                // An unadvertised currency carries no amount limits either, so a method kept above
                // skips the bounds check rather than indexing a missing key.
                continue;
            }

            if (
                $paymentAmount < $authorizedCurrencies[$activeCurrencyCode]['min_amount'] ||
                $paymentAmount > $authorizedCurrencies[$activeCurrencyCode]['max_amount']
            ) {
                unset($supportedMethods[$key]);

                continue;
            }
        }

        return $supportedMethods;
    }

    private function resolveAuthorizedCurrencies(string $factoryName): array
    {
        $account = $this->clientFactory->create($factoryName)->getAccount();

        $configuration = $account['configuration'] ?? [];
        Assert::isArray($configuration);
        $defaultMin = $configuration['min_amounts'] ?? [];
        Assert::isArray($defaultMin);
        $defaultMax = $configuration['max_amounts'] ?? [];
        Assert::isArray($defaultMax);

        $underscorePos = strpos($factoryName, '_');
        if ($underscorePos !== false) {
            $pmKey = substr($factoryName, $underscorePos + 1);
            $paymentMethods = $account['payment_methods'] ?? [];
            Assert::isArray($paymentMethods);
            $pmData = $paymentMethods[$pmKey] ?? [];
            Assert::isArray($pmData);
            $minAmounts = isset($pmData['min_amounts']) && \is_array($pmData['min_amounts']) ? $pmData['min_amounts'] : $defaultMin;
            $maxAmounts = isset($pmData['max_amounts']) && \is_array($pmData['max_amounts']) ? $pmData['max_amounts'] : $defaultMax;
        } else {
            $minAmounts = $defaultMin;
            $maxAmounts = $defaultMax;
        }

        $currencies = [];
        foreach ($minAmounts as $currency => $min) {
            Assert::string($currency);
            Assert::integer($min);
            if (isset($maxAmounts[$currency]) && \is_int($maxAmounts[$currency])) {
                $currencies[$currency] = ['min_amount' => $min, 'max_amount' => $maxAmounts[$currency]];
            }
        }

        return $currencies;
    }

    private function resolveAllowedCountries(string $factoryName): array
    {
        $underscorePos = strpos($factoryName, '_');
        if ($underscorePos === false) {
            return [];
        }

        $account = $this->clientFactory->create($factoryName)->getAccount();
        $pmKey = substr($factoryName, $underscorePos + 1);
        $paymentMethods = $account['payment_methods'] ?? [];
        Assert::isArray($paymentMethods);
        $pmData = $paymentMethods[$pmKey] ?? [];
        Assert::isArray($pmData);

        $allowedCountries = $pmData['allowed_countries'] ?? [];
        Assert::isArray($allowedCountries);

        if (\in_array('ALL', $allowedCountries, true)) {
            return [];
        }

        return $allowedCountries;
    }
}
