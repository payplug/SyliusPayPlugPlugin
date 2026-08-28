<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
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

    public function provide(
        array $supportedMethods,
        string $factoryName,
        int $paymentAmount,
        ?string $billingCountryCode = null,
    ): array {
        $activeCurrencyCode = $this->currencyContext->getCurrencyCode();
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
                unset($supportedMethods[$key]);

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
