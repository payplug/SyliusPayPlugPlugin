<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

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
