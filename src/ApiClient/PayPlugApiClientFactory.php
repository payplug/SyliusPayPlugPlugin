<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class PayPlugApiClientFactory implements PayPlugApiClientFactoryInterface
{
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
        private CacheInterface $cache,
    ) {
    }

    public function create(string $factoryName, ?string $key = null): PayPlugApiClientInterface
    {
        if (null === $key) {
            /** @var \Payum\Core\Model\GatewayConfig|null $gatewayConfig */
            $gatewayConfig = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

            if (null === $gatewayConfig) {
                throw new \LogicException('Not yet gateway created for ' . $factoryName);
            }
            $key = $gatewayConfig->getConfig()['secretKey'];
        }

        return new PayPlugApiClient($key, $factoryName, $this->cache);
    }

    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): PayPlugApiClientInterface
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig() ?? throw new \LogicException('Gateway config not found');

        $key = $gatewayConfig->getConfig()['secretKey'];
        $factoryName = $gatewayConfig->getFactoryName();

        return new PayPlugApiClient($key, $factoryName, $this->cache);
    }
}
