<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;

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

    public function isEnabled(string $gatewayName): bool
    {
        $paymentMethods = $this->client->getAccount()['payment_methods'];

        foreach ($paymentMethods as $key => $method) {
            if ($key !== $gatewayName) {
                continue;
            }

            return $method['enabled'];
        }

        return false;
    }
}
