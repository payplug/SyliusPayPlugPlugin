<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\SyliusConfigurationRepository;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class SyliusConfigurationRepositoryTest extends TestCase
{
    private RepositoryInterface&MockObject $gatewayConfigRepository;

    private GatewayConfigInterface&MockObject $gatewayConfig;

    private SyliusConfigurationRepository $repository;

    protected function setUp(): void
    {
        $this->gatewayConfigRepository = $this->createMock(RepositoryInterface::class);
        $this->gatewayConfig = $this->createMock(GatewayConfigInterface::class);

        $this->gatewayConfigRepository->method('findOneBy')
            ->with(['factoryName' => UhfGatewayFactory::FACTORY_NAME])
            ->willReturn($this->gatewayConfig)
        ;

        $this->repository = new SyliusConfigurationRepository($this->gatewayConfigRepository);
    }

    // -------------------------------------------------------------------------
    // getClientId() / getClientSecret() — live vs test credentials
    // -------------------------------------------------------------------------

    public function testGetClientId_whenLive_readsFromLiveClientConfig(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret'],
            'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret'],
        ]);

        self::assertSame('live_id', $this->repository->getClientId());
        self::assertSame('live_secret', $this->repository->getClientSecret());
    }

    public function testGetClientId_whenNotLive_readsFromTestClientConfig(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret'],
            'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret'],
        ]);

        self::assertSame('test_id', $this->repository->getClientId());
    }

    public function testGetClientId_whenNoClientConfig_returnsEmptyString(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true]);

        self::assertSame('', $this->repository->getClientId());
    }

    // -------------------------------------------------------------------------
    // getPublicKeyId() / getPublicKeyValue() — same storage, sibling keys
    // -------------------------------------------------------------------------

    public function testGetPublicKeyIdAndValue_readFromTheSameClientConfig(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['public_key_id' => 'pk_123', 'public_key_value' => 'pk_value'],
        ]);

        self::assertSame('pk_123', $this->repository->getPublicKeyId());
        self::assertSame('pk_value', $this->repository->getPublicKeyValue());
    }

    // -------------------------------------------------------------------------
    // get() / set() — generic top-level config key-value pair
    // -------------------------------------------------------------------------

    public function testGet_readsATopLevelConfigKey(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'payplug_webhook_authorization_header' => 'Bearer shared-secret',
        ]);

        self::assertSame('Bearer shared-secret', $this->repository->get('payplug_webhook_authorization_header'));
    }

    public function testGet_whenKeyIsMissing_returnsNull(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([]);

        self::assertNull($this->repository->get('payplug_webhook_authorization_header'));
    }

    public function testSet_writesTheKeyBackToTheGatewayConfigAndPersists(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true]);

        $this->gatewayConfig->expects(self::once())
            ->method('setConfig')
            ->with(['live' => true, 'payplug_webhook_authorization_header' => 'Bearer shared-secret'])
        ;
        $this->gatewayConfigRepository->expects(self::once())->method('add')->with($this->gatewayConfig);

        $this->repository->set('payplug_webhook_authorization_header', 'Bearer shared-secret');
    }
}
