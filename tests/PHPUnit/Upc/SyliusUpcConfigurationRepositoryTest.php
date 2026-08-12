<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\SyliusUpcConfigurationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class SyliusUpcConfigurationRepositoryTest extends TestCase
{
    private RepositoryInterface&MockObject $gatewayConfigRepository;

    private EntityManagerInterface&MockObject $entityManager;

    private SyliusUpcConfigurationRepository $configurationRepository;

    protected function setUp(): void
    {
        $this->gatewayConfigRepository = $this->createMock(RepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configurationRepository = new SyliusUpcConfigurationRepository($this->gatewayConfigRepository, $this->entityManager);
    }

    private function gatewayConfigWith(array $config): GatewayConfigInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn($config);
        $this->gatewayConfigRepository->method('findOneBy')
            ->with(['factoryName' => UhfGatewayFactory::FACTORY_NAME])
            ->willReturn($gatewayConfig);

        return $gatewayConfig;
    }

    public function testGetClientId_whenLive_readsFromLiveClient(): void
    {
        $this->gatewayConfigWith(['live' => true, 'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret']]);

        self::assertSame('live_id', $this->configurationRepository->getClientId());
    }

    public function testGetClientId_whenNotLive_readsFromTestClient(): void
    {
        $this->gatewayConfigWith(['live' => false, 'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret']]);

        self::assertSame('test_id', $this->configurationRepository->getClientId());
    }

    public function testGetClientSecret_whenLive_readsFromLiveClient(): void
    {
        $this->gatewayConfigWith(['live' => true, 'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret']]);

        self::assertSame('live_secret', $this->configurationRepository->getClientSecret());
    }

    public function testGetClientId_whenNoClientConfigStored_returnsEmptyString(): void
    {
        $this->gatewayConfigWith(['live' => false]);

        self::assertSame('', $this->configurationRepository->getClientId());
    }

    public function testGetPublicKeyId_readsHfIdentifierDefault(): void
    {
        $this->gatewayConfigWith(['hfIdentifierDefault' => 'hf_ident_123']);

        self::assertSame('hf_ident_123', $this->configurationRepository->getPublicKeyId());
    }

    public function testGetPublicKeyValue_returnsEmptyString(): void
    {
        $this->gatewayConfigWith([]);

        self::assertSame('', $this->configurationRepository->getPublicKeyValue());
    }

    public function testGet_readsArbitraryKeyFromConfig(): void
    {
        $this->gatewayConfigWith(['payplug_webhook_authorization_header' => 'Bearer shared-secret']);

        self::assertSame('Bearer shared-secret', $this->configurationRepository->get('payplug_webhook_authorization_header'));
    }

    public function testGet_whenKeyMissing_returnsNull(): void
    {
        $this->gatewayConfigWith([]);

        self::assertNull($this->configurationRepository->get('missing_key'));
    }

    public function testSet_mergesTheKeyIntoConfigAndFlushes(): void
    {
        $gatewayConfig = $this->gatewayConfigWith(['existing' => 'value']);
        $gatewayConfig->expects(self::once())->method('setConfig')
            ->with(['existing' => 'value', 'new_key' => 'new_value']);
        $this->entityManager->expects(self::once())->method('flush');

        $this->configurationRepository->set('new_key', 'new_value');
    }
}
