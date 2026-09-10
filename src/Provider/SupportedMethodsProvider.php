<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException;

final class SupportedMethodsProvider
{
    public function __construct(
        private CurrencyContextInterface $currencyContext,
        private PayPlugApiClientFactoryInterface $clientFactory,
        private AccountAmountRangeResolver $amountRangeResolver,
        private LoggerInterface $logger,
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
     * ScalapayGatewayConfigurationTypeExtension lets the merchant tighten the API-provided bounds
     * via the min_amount/max_amount config keys. The override is deliberately scoped to Scalapay:
     * IsScalapayAmountRangeValidValidator — the save-time guardrail that keeps the configured
     * range inside what PayPlug authorizes — is wired for Scalapay only, so honouring the same
     * keys on another gateway would grant it a checkout override with no validation behind it.
     * The values are entered in EUR, so the override also only applies to an EUR checkout; other
     * currencies keep the raw API bounds.
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
        if ('EUR' !== $activeCurrencyCode || ScalapayGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            return [$authorizedRange['min_amount'], $authorizedRange['max_amount']];
        }

        [$minAmount, $maxAmount] = $this->readConfiguredAmounts($gatewayConfig->getConfig());

        return [
            $minAmount ?? $authorizedRange['min_amount'],
            $maxAmount ?? $authorizedRange['max_amount'],
        ];
    }

    /**
     * The admin form only ever writes null or an int, but the gateway config is a plain serialized
     * array that a direct DB edit, an import script or an admin API write can leave anything in.
     * provide() runs unguarded on every checkout page (via the gateway resolver decorators), so a
     * malformed value degrades to "not configured" — falling back to the API bounds — rather than
     * throwing an assertion error that would break payment-method resolution for the whole
     * checkout, not just hide Scalapay.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readConfiguredAmounts(array $config): array
    {
        $minAmount = $config[ScalapayGatewayFactory::MIN_AMOUNT] ?? null;
        $maxAmount = $config[ScalapayGatewayFactory::MAX_AMOUNT] ?? null;

        try {
            Assert::nullOrInteger($minAmount);
            Assert::nullOrInteger($maxAmount);
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning('Ignoring malformed Scalapay amount range in gateway config; falling back to the PayPlug API bounds.', [
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'exception' => $exception->getMessage(),
            ]);

            return [null, null];
        }

        return [$minAmount, $maxAmount];
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
