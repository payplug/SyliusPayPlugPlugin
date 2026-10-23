<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
        private PproEligibilityChecker $pproEligibilityChecker,
        private AccountAllowedCountriesResolver $allowedCountriesResolver,
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

        /** @var array<string, array{currencies: array<string, array{min_amount: int, max_amount: int}>, allowedCountries: list<string>|null}|null> $accounts */
        $accounts = [];

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if ($factoryName !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            // A PPRO method is filtered from its stored PayPlug limits; until they are stored, it is
            // filtered live from the account like every other method.
            $isSupported = PproPaymentMethods::isPpro($factoryName)
                ? $this->pproEligibilityChecker->isEligible($paymentMethod, $paymentAmount, $activeCurrencyCode, $billingCountryCode)
                : null;

            if (null === $isSupported) {
                $memoKey = $this->accountMemoKey($gatewayConfig);
                if (!\array_key_exists($memoKey, $accounts)) {
                    $accounts[$memoKey] = $this->readAccountLimits($paymentMethod, $gatewayConfig);
                }

                $isSupported = $this->isWithinAccountLimits($accounts[$memoKey], $gatewayConfig, $paymentAmount, $activeCurrencyCode, $billingCountryCode);
            }

            if (!$isSupported) {
                unset($supportedMethods[$key]);
            }
        }

        return $supportedMethods;
    }

    /**
     * @param array{currencies: array<string, array{min_amount: int, max_amount: int}>, allowedCountries: list<string>|null}|null $accountLimits null when the account could not be read
     */
    private function isWithinAccountLimits(
        ?array $accountLimits,
        GatewayConfigInterface $gatewayConfig,
        int $paymentAmount,
        string $activeCurrencyCode,
        ?string $billingCountryCode,
    ): bool {
        if (null === $accountLimits) {
            return true;
        }

        $allowedCountries = $accountLimits['allowedCountries'];
        if (null !== $billingCountryCode && null !== $allowedCountries && !\in_array($billingCountryCode, $allowedCountries, true)) {
            return false;
        }

        return $this->isWithinCurrencyLimits($accountLimits['currencies'], $gatewayConfig, $paymentAmount, $activeCurrencyCode);
    }

    /**
     * @param array<string, array{min_amount: int, max_amount: int}> $authorizedCurrencies
     */
    private function isWithinCurrencyLimits(
        array $authorizedCurrencies,
        GatewayConfigInterface $gatewayConfig,
        int $paymentAmount,
        string $activeCurrencyCode,
    ): bool {
        if (!\array_key_exists($activeCurrencyCode, $authorizedCurrencies)) {
            // Unified Hosted Fields is exempt from the currency gate, pending a source of truth
            // that actually knows a UHF account's currencies. The Retail `/account` payload only
            // advertises the legacy acquiring setup (`configuration.min_amounts` /
            // `max_amounts`), which is demonstrably wrong for a UHF account: account 1461487
            // reports `currencies: ['EUR']` while a USD payment on that same account completed
            // on staging (schemeTransactionId 6a9ad8eb905d3). Hiding a method that works is the
            // worse failure, so UHF is kept and left to the API to accept or refuse.
            //
            // Every other gateway keeps the gate: Apple Pay and American Express are EUR-only,
            // and `payplug` in integrated_payment mode is served by the very legacy setup this
            // payload does describe correctly. An unadvertised currency carries no amount limits
            // either, so a kept method skips the bounds check.
            return PayPlugGatewayFactory::isHostedFieldsConfig($gatewayConfig);
        }

        $authorizedRange = $authorizedCurrencies[$activeCurrencyCode];

        return $paymentAmount >= $authorizedRange['min_amount'] && $paymentAmount <= $authorizedRange['max_amount'];
    }

    /**
     * Two payment methods of the same factory can be configured on different PayPlug accounts, so
     * the `/account` payload is memoized per gateway config rather than once per call — sharing one
     * lookup across the loop let the first method's account govern every later one. The persisted
     * id is the key; object identity covers a config that has not been flushed yet, whose null id
     * would otherwise collide with every other unsaved one.
     */
    private function accountMemoKey(GatewayConfigInterface $gatewayConfig): string
    {
        $id = $gatewayConfig->getId();

        if (\is_int($id) || (\is_string($id) && '' !== $id)) {
            return 'config:' . $id;
        }

        return 'object:' . spl_object_id($gatewayConfig);
    }

    /**
     * A PPRO gateway reaches this point only while no PayPlug limits are stored for it; for every
     * other gateway the `/account` payload is read live (through the token-keyed cache of
     * PayPlugApiClient). A failure to read or parse it keeps the method rather than breaking
     * payment-method resolution for the whole checkout, and is memoized by the caller like a
     * successful read.
     *
     * The factory name is read off the gateway config the account is fetched for, so the payload and
     * the key used to index it always come from the same config.
     *
     * @return array{currencies: array<string, array{min_amount: int, max_amount: int}>, allowedCountries: list<string>|null}|null
     */
    private function readAccountLimits(
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig,
    ): ?array {
        try {
            $account = $this->clientFactory->createForPaymentMethod($paymentMethod)->getAccount();
            $paymentMethodKey = $this->paymentMethodKey($gatewayConfig);

            return [
                'currencies' => $this->amountRangeResolver->resolve($account, $paymentMethodKey),
                'allowedCountries' => $this->allowedCountriesResolver->resolve($account, $paymentMethodKey),
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('[PayPlug] Could not read the PayPlug account; the payment method is kept.', [
                'factory_name' => $gatewayConfig->getFactoryName(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The `/account` payload keys each payment method under the factory name's suffix —
     * `payplug_apple_pay` is advertised as `apple_pay`. A suffix-less factory name (`payplug`) is the card gateway, which
     * has no such sub-payload.
     */
    private function paymentMethodKey(GatewayConfigInterface $gatewayConfig): ?string
    {
        // provide()'s loop guard has already matched this config against a non-null factory name,
        // so the null coalesce is unreachable from there; it keeps the helper total for any later
        // caller, and an empty name carries no suffix anyway.
        $factoryName = $gatewayConfig->getFactoryName() ?? '';
        $underscorePos = strpos($factoryName, '_');

        return false !== $underscorePos ? substr($factoryName, $underscorePos + 1) : null;
    }
}
