<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Authentication;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Config\SyliusPayment\GatewayConfigConfig;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PayPlugApiClientFactory implements PayPlugApiClientFactoryInterface
{
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
        private CacheInterface $cache,
    ) {
    }

    public function create(string $factoryName): PayPlugApiClientInterface
    {
        /** @var GatewayConfigInterface|null $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

        if (null === $gatewayConfig) {
            throw new \LogicException('Not yet gateway created for ' . $factoryName);
        }

        $key = $this->getTokenForGatewayConfig($gatewayConfig);

        return new PayPlugApiClient($key, $factoryName, $this->cache);
    }

    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): PayPlugApiClientInterface
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig() ?? throw new \LogicException('Gateway config not found');

        $key = $this->getTokenForGatewayConfig($gatewayConfig);
        $factoryName = $gatewayConfig->getFactoryName();

        return new PayPlugApiClient($key, $factoryName, $this->cache);
    }

    private function getTokenForGatewayConfig(GatewayConfigInterface $gatewayConfig): string
    {
        $config = $gatewayConfig->getConfig();
        $clientConfig = $config['live_client'];
        if (true !== $config['live']) { // The live mode is not enabled, use client config for test mode
            $clientConfig = $config['test_client'];
        }
        $cacheKey = sprintf('payplug_%s_api_key_%s', $gatewayConfig->getFactoryName(), $config['live'] === true ? 'live' : 'test');

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($clientConfig) {
            $response = Authentication::generateJWT($clientConfig['client_id'], $clientConfig['client_secret']);
            if ([] === $response) {
                throw new \LogicException('Unable to connect to PayPlug API. Please check your credentials in the PayPlug plugin configuration.');
            }

            $item->expiresAfter($response['httpResponse']['expires_in']);
            /** @var string */
            return $response['httpResponse']['access_token'];
        });
    }
}
