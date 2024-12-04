<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

class PayplugFeatureChecker
{
    public function isDeferredCaptureEnabled(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->getConfigCheckboxValue($paymentMethod, PayPlugGatewayFactory::DEFERRED_CAPTURE);
    }

    public function isIntegratedPaymentEnabled(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->getConfigCheckboxValue($paymentMethod, PayPlugGatewayFactory::INTEGRATED_PAYMENT);
    }

    public function isOneClickEnabled(PaymentMethodInterface $paymentMethod): bool
    {
        return $this->getConfigCheckboxValue($paymentMethod, PayPlugGatewayFactory::ONE_CLICK);
    }

    private function getConfigCheckboxValue(PaymentMethodInterface $paymentMethod, string $configKey): bool
    {
        $gatewayConfiguration = $paymentMethod->getGatewayConfig();

        if (!$gatewayConfiguration instanceof GatewayConfigInterface) {
            return false;
        }

        if (!\array_key_exists($configKey, $gatewayConfiguration->getConfig())) {
            return false;
        }

        return (bool) ($gatewayConfiguration->getConfig()[$configKey] ?? false);
    }
}
