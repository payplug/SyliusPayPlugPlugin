<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Action\Api;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Exception\UnsupportedApiException;

/**
 * NOT deprecated as a whole: the $payPlugApiClient property below is still used by live code.
 * {@see \PayPlug\SyliusPayPlugPlugin\Controller\OneClickAction}, a routed controller, uses this
 * trait for that property alone and assigns it itself from PayPlugApiClientFactory. Only setApi()
 * — the Payum ApiAwareInterface entry point — is dead.
 */
trait ApiAwareTrait
{
    /** @var PayPlugApiClientInterface */
    protected $payPlugApiClient;

    /**
     * @deprecated Dead Payum wiring — never called at runtime.
     *
     * Payum assigns the API through this method when it builds a gateway, but no Payum gateway is
     * ever registered for the `payplug*` factory names (config/services/gateway.xml, the only
     * source of `payum.gateway_factory_builder` tags, is not loaded by the bundle extension).
     * Live code obtains its client from PayPlugApiClientFactoryInterface::createForPaymentMethod()
     * instead, which is channel-aware.
     *
     * Will be removed permanently in early 2027.
     */
    public function setApi($api): void
    {
        if (!$api instanceof PayPlugApiClientInterface) {
            throw new UnsupportedApiException('Not supported.Expected an instance of ' . PayPlugApiClientInterface::class);
        }
        $this->payPlugApiClient = $api;
    }
}
