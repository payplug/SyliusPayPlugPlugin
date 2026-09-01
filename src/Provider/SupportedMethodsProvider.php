<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Webmozart\Assert\Assert;

final class SupportedMethodsProvider
{
    public function __construct(
        private CurrencyContextInterface $currencyContext,
        private PayPlugApiClientFactoryInterface $clientFactory,
        private AccountAmountRangeResolver $amountRangeResolver,
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

            [$minAmount, $maxAmount] = $this->resolveAmountBounds(
                $gatewayConfig,
                $activeCurrencyCode,
                $authorizedCurrencies[$activeCurrencyCode],
            );

            if ($paymentAmount < $minAmount || $paymentAmount > $maxAmount) {
                unset($supportedMethods[$key]);
            }
        }

        return $supportedMethods;
    }

    /**
     * A gateway's own config extension (e.g. ScalapayGatewayConfigurationTypeExtension) may let the
     * merchant tighten the API-provided bounds via min_amount/max_amount config keys, entered as
     * EUR — so the override only applies when checkout is actually in EUR; other currencies keep
     * the raw API bounds.
     *
     * @param array{min_amount: int, max_amount: int} $authorizedRange
     *
     * @return array{0: int, 1: int}
     */
    private function resolveAmountBounds(
        GatewayConfigInterface $gatewayConfig,
        string $activeCurrencyCode,
        array $authorizedRange,
    ): array {
        if ('EUR' !== $activeCurrencyCode) {
            return [$authorizedRange['min_amount'], $authorizedRange['max_amount']];
        }

        $config = $gatewayConfig->getConfig();
        $minAmount = $config[ScalapayGatewayFactory::MIN_AMOUNT] ?? null;
        $maxAmount = $config[ScalapayGatewayFactory::MAX_AMOUNT] ?? null;
        Assert::nullOrInteger($minAmount);
        Assert::nullOrInteger($maxAmount);

        return [
            $minAmount ?? $authorizedRange['min_amount'],
            $maxAmount ?? $authorizedRange['max_amount'],
        ];
    }

    /**
     * @return array<string, array{min_amount: int, max_amount: int}>
     */
    private function resolveAuthorizedCurrencies(string $factoryName): array
    {
        $account = $this->clientFactory->create($factoryName)->getAccount();
        $underscorePos = strpos($factoryName, '_');
        $paymentMethodKey = false !== $underscorePos ? substr($factoryName, $underscorePos + 1) : null;

        return $this->amountRangeResolver->resolve($account, $paymentMethodKey);
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
