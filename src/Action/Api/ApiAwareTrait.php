<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Api;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Exception\UnsupportedApiException;

trait ApiAwareTrait
{
    /** @var PayPlugApiClientInterface */
    protected $payPlugApiClient;

    public function setApi($payPlugApiClient): void
    {
        if (!$payPlugApiClient instanceof PayPlugApiClientInterface) {
            throw new UnsupportedApiException('Not supported.Expected an instance of ' . PayPlugApiClientInterface::class);
        }
        $this->payPlugApiClient = $payPlugApiClient;
    }
}
