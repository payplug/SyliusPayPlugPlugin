<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use Doctrine\Common\Collections\Collection;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;

final class CanSavePayplugPaymentMethodChecker
{
    public function __construct(private PayPlugApiClientInterface $client)
    {
    }

    public function isLive(): bool
    {
        return (bool) ($this->client->getAccount()['is_live']);
    }

    public function isEnabled(string $factoryName, Collection $channels): bool
    {
        $paymentMethods = $this->client->getAccount()['payment_methods'];
        $paymentMethodName = substr($factoryName, (int) (strpos($factoryName, '_', 0)) + 1);

        foreach ($paymentMethods as $key => $method) {
            if ($key !== $paymentMethodName) {
                continue;
            }

            if (ApplePayGatewayFactory::FACTORY_NAME !== $factoryName) {
                return $method['enabled'];
            }

            return $this->isAllowedDomainNames($method, $channels);
        }

        return false;
    }

    private function isAllowedDomainNames(array $method, Collection $channels): bool
    {
        if (!$method['enabled'] || !array_key_exists('allowed_domain_names', $method) || $channels->isEmpty()) {
            return false;
        }

        foreach ($channels as $channel) {
            if (!in_array($channel->getHostname(), $method['allowed_domain_names'], true)) {
                return false;
            }
        }

        return true;
    }
}
