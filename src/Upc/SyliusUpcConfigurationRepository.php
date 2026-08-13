<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class SyliusUpcConfigurationRepository implements IConfigurationRepository
{
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->findGatewayConfig()->getConfig()[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $gatewayConfig = $this->findGatewayConfig();
        $gatewayConfig->setConfig([...$gatewayConfig->getConfig(), $key => $value]);
        $this->entityManager->flush();
    }

    public function getClientId(): string
    {
        $value = $this->getClientConfig()['client_id'] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function getClientSecret(): string
    {
        $value = $this->getClientConfig()['client_secret'] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function getPublicKeyId(): string
    {
        $value = $this->findGatewayConfig()->getConfig()[PayPlugGatewayFactory::HF_IDENTIFIER] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * Not populated by any current admin field. Returns '' until a future ticket adds a
     * distinct public-key-value config field.
     */
    public function getPublicKeyValue(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function getClientConfig(): array
    {
        $config = $this->findGatewayConfig()->getConfig();
        $isLive = true === ($config['live'] ?? false);
        $rawClientConfig = $isLive ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);

        if (!\is_array($rawClientConfig)) {
            return [];
        }

        /** @var array<string, mixed> $clientConfig */
        $clientConfig = $rawClientConfig;

        return $clientConfig;
    }

    private function findGatewayConfig(): GatewayConfigInterface
    {
        /** @var GatewayConfigInterface|null $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigRepository->findOneBy(['factoryName' => PayPlugGatewayFactory::FACTORY_NAME]);

        return $gatewayConfig ?? throw new \LogicException('No gateway config found for ' . PayPlugGatewayFactory::FACTORY_NAME . '.');
    }
}
