<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use Doctrine\ORM\PersistentCollection;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;

final class CanSavePayplugPaymentMethodChecker
{
    private PayPlugApiClientInterface $client;

    public function __construct(PayPlugApiClientInterface $apiClient)
    {
        $this->client = $apiClient;
    }

    public function isLive(): bool
    {
        return (bool) ($this->client->getAccount()['is_live']);
    }

    public function isEnabled(string $gatewayName, PersistentCollection $channels): bool
    {
        $paymentMethods = $this->client->getAccount()['payment_methods'];

        foreach ($paymentMethods as $key => $method) {
            if ($key !== $gatewayName) {
                continue;
            }
            if (ApplePayGatewayFactory::PAYMENT_METHOD_APPLE_PAY !== $gatewayName) {
                return $method['enabled'];
            }

            return $this->isAllowedDomainNames($method, $channels);
        }

        return false;
    }

    private function isAllowedDomainNames(array $method, PersistentCollection $channels): bool
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
