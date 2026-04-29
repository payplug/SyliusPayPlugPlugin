<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Authentication;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
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
        $rawClientConfig = true !== $config['live'] ? $config['test_client'] : $config['live_client'];
        if (!\is_array($rawClientConfig)) {
            throw new GatewayConfigurationException('No client config found for ' . $gatewayConfig->getFactoryName() . '. Please renew your credentials in the PayPlug plugin configuration.');
        }
        /** @var array<string, string> $clientConfig */
        $clientConfig = $rawClientConfig;
        $cacheKey = sprintf('payplug_%s_api_key_%s', $gatewayConfig->getFactoryName(), $config['live'] === true ? 'live' : 'test');

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($clientConfig) {
            $response = Authentication::generateJWT($clientConfig['client_id'] ?? '', $clientConfig['client_secret'] ?? '');
            if ([] === $response || !is_array($response['httpResponse'])) {
                throw new GatewayConfigurationException('Unable to connect to PayPlug API. Please check your credentials in the PayPlug plugin configuration.');
            }

            $accessToken = $response['httpResponse']['access_token'];
            if (!is_string($accessToken)) {
                throw new GatewayConfigurationException('Unable to connect to PayPlug API. Please check your credentials in the PayPlug plugin configuration.');
            }
            $expiresIn = $response['httpResponse']['expires_in'];
            if (!is_int($expiresIn)) {
                $expiresIn = 200;
            }

            $item->expiresAfter($expiresIn);

            return $accessToken;
        });
    }
}
