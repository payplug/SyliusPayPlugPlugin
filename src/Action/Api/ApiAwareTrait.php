<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Api;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Exception\UnsupportedApiException;

trait ApiAwareTrait
{
    /** @var PayPlugApiClientInterface */
    protected $payPlugApiClient;

    public function setApi($api): void
    {
        if (!$api instanceof PayPlugApiClientInterface) {
            throw new UnsupportedApiException('Not supported.Expected an instance of ' . PayPlugApiClientInterface::class);
        }
        $this->payPlugApiClient = $api;
    }
}
