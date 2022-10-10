<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PayPlugApiClientFactory implements PayPlugApiClientFactoryInterface
{
    /** @var ContainerInterface */
    private $container;

    /** @var string */
    private $serviceName;

    public function __construct(ContainerInterface $container, string $serviceName)
    {
        $this->container = $container;
        $this->serviceName = $serviceName;
    }

    public function create(string $factoryName, ?string $key = null): PayPlugApiClientInterface
    {
        return new PayPlugApiClient($this->container, $this->serviceName);
    }
}
