<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Checkout eligibility of a PPRO payment method, read from its stored configuration only. Without
 * stored data the method is kept and PayPlug arbitrates at payment time.
 */
final class PproEligibilityChecker
{
    private const CURRENCY = 'EUR';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function isEligible(
        PaymentMethodInterface $paymentMethod,
        int $amount,
        string $currencyCode,
        ?string $billingCountryCode,
    ): bool {
        if (self::CURRENCY !== $currencyCode) {
            return false;
        }

        $config = $this->readStoredConfig($paymentMethod);
        if (null === $config) {
            return true;
        }

        if ($config->hasEmptyAmountRange()) {
            $this->logger->warning('[PayPlug] The PPRO amount range is empty; the payment method is hidden until its limits are refreshed.', [
                'factory_name' => $paymentMethod->getGatewayConfig()?->getFactoryName(),
                'min' => $config->effectiveMin(),
                'max' => $config->effectiveMax(),
            ]);
        }

        return $config->isEligible($amount, $billingCountryCode);
    }

    /**
     * Null when nothing is stored, or when the stored block is malformed (logged).
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

        return $config;
    }
}
