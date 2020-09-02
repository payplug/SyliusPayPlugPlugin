<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Sylius\Component\Resource\Repository\RepositoryInterface;

final class PayPlugApiClientFactory
{
    /** @var \Sylius\Component\Resource\Repository\RepositoryInterface */
    private $gatewayConfigRepository;

    public function __construct(
        RepositoryInterface $gatewayConfigRepository
    ) {
        $this->gatewayConfigRepository = $gatewayConfigRepository;
    }

    public function create(string $factoryName): PayPlugApiClientInterface
    {
        /** @var \Payum\Core\Model\GatewayConfig|null $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigRepository->findOneBy(['factoryName' => $factoryName]);

        if (null === $gatewayConfig) {
            throw new \LogicException('Not yet gateway created for ' . $factoryName);
        }

        return new PayPlugApiClient($gatewayConfig->getConfig()['secretKey'], $factoryName);
    }
}
