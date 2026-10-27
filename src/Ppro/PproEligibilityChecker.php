<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Checkout eligibility of a PPRO payment method, read from its stored configuration only. Without
 * stored PayPlug limits it cannot decide, and the caller falls back to the live account check.
 */
final class PproEligibilityChecker
{
    private const CURRENCY = 'EUR';

    /**
     * A disabled flag older than this no longer hides the method: PayPlug may have activated it
     * since, and it decides at payment time.
     */
    private const DISABLED_FLAG_TTL = '-24 hours';

    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool|null null when no PayPlug limits are stored for the method
     */
    public function isEligible(
        PaymentMethodInterface $paymentMethod,
        int $amount,
        string $currencyCode,
        ?string $billingCountryCode,
    ): ?bool {
        if (self::CURRENCY !== $currencyCode) {
            return false;
        }

        $config = $this->readStoredConfig($paymentMethod);
        if (null === $config) {
            return null;
        }

        $trustDisabledFlag = $config->isRefreshedSince($this->clock->now()->modify(self::DISABLED_FLAG_TTL));

        return $config->isEligible($amount, $billingCountryCode, $trustDisabledFlag);
    }

    /**
     * The merchant's own range and countries, applied on top of the live account check while no
     * usable PayPlug limits are stored: those come from the account then, not from the stored block.
     */
    public function isAllowedByMerchantSettings(
        PaymentMethodInterface $paymentMethod,
        int $amount,
        ?string $billingCountryCode,
    ): bool {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $settings = PproMerchantSettings::readMerchantOnly($gatewayConfig?->getFactoryName(), $gatewayConfig?->getConfig() ?? []);

        return null === $settings || $settings->isEligible($amount, $billingCountryCode);
    }

    /**
     * Null when nothing is stored, when only merchant settings are (the PayPlug limits were never
     * captured, or were cleared by a logout), when the limits were fetched in the other environment
     * (TEST vs LIVE), or when the stored block is malformed (logged).
     */
    private function readStoredConfig(PaymentMethodInterface $paymentMethod): ?PproConfig
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $stored = $gatewayConfig?->getConfig()[PproConfig::CONFIG_KEY] ?? null;
        $config = null === $stored ? null : PproConfig::fromArray($stored);

        if (null !== $stored && null === $config) {
            $this->logger->warning('[PayPlug] Ignoring a malformed stored PPRO configuration.', [
                'factory_name' => $gatewayConfig?->getFactoryName(),
            ]);
        }

        $live = true === ($gatewayConfig?->getConfig()['live'] ?? false);

        return null === $config?->refreshedAt || $config->live !== $live ? null : $config;
    }
}
