<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class PayPlugApiClientFactory
{
    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $gatewayConfigRepository;

    /** @var CacheInterface */
    private $cache;

    public function __construct(
        RepositoryInterface $gatewayConfigRepository,
        CacheInterface $cache
    ) {
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->cache = $cache;
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
}
