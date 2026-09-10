<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Exceptions\ApiException;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class PayPlugApiClientFactory implements PayPlugApiClientFactoryInterface
{
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
        private CacheInterface $cache,
        private TokenManager $tokenManager,
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
        $isLive = true === ($config['live'] ?? false);
        $rawClientConfig = $isLive ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);
        if (!\is_array($rawClientConfig)) {
            throw new GatewayConfigurationException('No client config found for ' . $gatewayConfig->getFactoryName() . '. Please renew your credentials in the PayPlug plugin configuration.');
        }
        /** @var array<string, string> $clientConfig */
        $clientConfig = $rawClientConfig;

        $clientId = $clientConfig['client_id'] ?? '';
        $clientSecret = $clientConfig['client_secret'] ?? '';
        if ('' === $clientId || '' === $clientSecret) {
            throw new GatewayConfigurationException('No client config found for ' . $gatewayConfig->getFactoryName() . '. Please renew your credentials in the PayPlug plugin configuration.');
        }

        try {
            return $this->tokenManager->getValidToken($clientId, $clientSecret);
        } catch (ApiException $e) {
            throw new GatewayConfigurationException('Unable to connect to PayPlug API. Please check your credentials in the PayPlug plugin configuration.', 0, $e);
        }
    }
}
