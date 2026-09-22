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

    /**
     * Channel-ambiguous: since PRE-3628 several enabled gateway configs may share a factory name —
     * one per channel — and findOneBy() then returns an arbitrary one of them, so the client this
     * returns may carry another channel's account credentials.
     *
     * @internal Kept off {@see PayPlugApiClientFactoryInterface} so no application class can reach
     *           it; the sole remaining callers are the `payplug_sylius_payplug_plugin.api_client.*`
     *           service-factory definitions in config/services/client.xml, which are #[Autowire]d
     *           into seven services that have no payment method in scope. Use
     *           {@see self::createForPaymentMethod()} everywhere else. Removed once those
     *           singletons are made channel-aware — the open half of PRE-3682.
     */
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
