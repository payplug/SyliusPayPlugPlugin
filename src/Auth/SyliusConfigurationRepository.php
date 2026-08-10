<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class SyliusConfigurationRepository implements IConfigurationRepository
{
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->getConfig()[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $gatewayConfig = $this->getGatewayConfig();
        $config = $gatewayConfig->getConfig();
        $config[$key] = $value;
        $gatewayConfig->setConfig($config);
        $this->gatewayConfigRepository->add($gatewayConfig);
    }

    public function getClientId(): string
    {
        return $this->getClientConfig()['client_id'] ?? '';
    }

    public function getClientSecret(): string
    {
        return $this->getClientConfig()['client_secret'] ?? '';
    }

    public function getPublicKeyId(): string
    {
        return $this->getClientConfig()['public_key_id'] ?? '';
    }

    public function getPublicKeyValue(): string
    {
        return $this->getClientConfig()['public_key_value'] ?? '';
    }

    /** @return array<string, mixed> */
    private function getConfig(): array
    {
        return $this->getGatewayConfig()->getConfig();
    }

    private function getGatewayConfig(): GatewayConfigInterface
    {
        /** @var GatewayConfigInterface|null $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigRepository->findOneBy(['factoryName' => UhfGatewayFactory::FACTORY_NAME]);

        if (null === $gatewayConfig) {
            throw new \LogicException('No gateway config found for ' . UhfGatewayFactory::FACTORY_NAME);
        }

        return $gatewayConfig;
    }

    /** @return array<string, string> */
    private function getClientConfig(): array
    {
        $config = $this->getConfig();
        $isLive = true === ($config['live'] ?? false);
        $rawClientConfig = $isLive ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);
        if (!\is_array($rawClientConfig)) {
            return [];
        }

        /** @var array<string, string> $clientConfig */
        $clientConfig = $rawClientConfig;

        return $clientConfig;
    }
}
