<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;

final class CanSaveBancontactMethodChecker
{
    /** @var PayPlugApiClientInterface */
    private $client;

    public function __construct(PayPlugApiClientInterface $apiClient)
    {
        $this->client = $apiClient;
    }

    public function isLive(): bool
    {
        return (bool) ($this->client->getAccount()['is_live']);
    }

    public function isEnabled(): bool
    {
        $paymentMethods = $this->client->getAccount()['payment_methods'];

        foreach ($paymentMethods as $key => $method) {
            if ($key !== BancontactGatewayFactory::PAYMENT_METHOD_BANCONTACT) {
                continue;
            }

            return $method['enabled'];
        }

        return false;
    }
}
