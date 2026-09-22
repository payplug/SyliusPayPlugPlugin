<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\ScopedConfigurationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\SyliusUpcConfigurationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class SyliusUpcConfigurationRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private SyliusUpcConfigurationRepository $configurationRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configurationRepository = new SyliusUpcConfigurationRepository($this->entityManager);
    }

    private function gatewayConfig(array $config): GatewayConfigInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn($config);

        return $gatewayConfig;
    }

    /** A repository scoped to a config carrying $config — the only supported way to read anything. */
    private function scopedWith(array $config): ScopedConfigurationRepositoryInterface
    {
        return $this->configurationRepository->withGatewayConfig($this->gatewayConfig($config));
    }

    // -------------------------------------------------------------------------
    // Explicit scoping — withGatewayConfig()
    // -------------------------------------------------------------------------

    /**
     * Since PRE-3628 several enabled CB gateway configs may exist, one per channel. The scoped
     * repository reads the config it was handed; the factory-name lookup that used to resolve an
     * arbitrary channel's account is gone, along with the gateway config repository it needed.
     */
    public function testWithGatewayConfig_readsCredentialsFromTheScopedConfig(): void
    {
        $scoped = $this->configurationRepository->withGatewayConfig($this->gatewayConfig([
            'live' => false,
            'test_client' => ['client_id' => 'de_id', 'client_secret' => 'de_secret'],
        ]));

        self::assertSame('de_id', $scoped->getClientId());
        self::assertSame('de_secret', $scoped->getClientSecret());
    }

    /**
     * The whole point of the ticket: two enabled CB configs on different channels, each resolving
     * its own credentials. Also pins the wither as immutable — scoping for DE must not disturb the
     * instance already scoped to FR, which is what makes the shared service safe to reuse.
     */
    public function testWithGatewayConfig_withTwoChannelsSharingTheCbFactory_keepsEachScopeIndependent(): void
    {
        $fr = $this->configurationRepository->withGatewayConfig($this->gatewayConfig([
            'live' => true,
            'live_client' => ['client_id' => 'fr_id', 'client_secret' => 'fr_secret'],
        ]));
        $de = $this->configurationRepository->withGatewayConfig($this->gatewayConfig([
            'live' => true,
            'live_client' => ['client_id' => 'de_id', 'client_secret' => 'de_secret'],
        ]));

        self::assertSame('fr_id', $fr->getClientId());
        self::assertSame('de_id', $de->getClientId());
        self::assertNotSame($fr, $de);
    }

    /**
     * Every consumer holds a PaymentMethodInterface rather than a bare gateway config, so this is
     * the form they actually use; it exists to keep the null-check in one place instead of five.
     */
    public function testForPaymentMethod_scopesToThatMethodsOwnGatewayConfig(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($this->gatewayConfig([
            'live' => false,
            'test_client' => ['client_id' => 'fr_id', 'client_secret' => 'fr_secret'],
        ]));

        self::assertSame('fr_id', $this->configurationRepository->forPaymentMethod($method)->getClientId());
    }

    public function testForPaymentMethod_whenTheMethodHasNoGatewayConfig_throws(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->configurationRepository->forPaymentMethod($method);
    }

    /**
     * A loud failure at an unscoped call site beats the previous silent wrong-account behaviour:
     * any path that forgot to scope reports itself at runtime instead of signing with whichever
     * config Doctrine returned first.
     */
    public function testGetClientId_whenUnscoped_throwsInsteadOfResolvingAnArbitraryConfig(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not been scoped');

        $this->configurationRepository->getClientId();
    }

    public function testGet_whenUnscoped_throwsInsteadOfResolvingAnArbitraryConfig(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not been scoped');

        $this->configurationRepository->get('payplug_webhook_authorization_header');
    }

    public function testGetClientId_whenLive_readsFromLiveClient(): void
    {
        $scoped = $this->scopedWith(['live' => true, 'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret']]);

        self::assertSame('live_id', $scoped->getClientId());
    }

    public function testGetClientId_whenNotLive_readsFromTestClient(): void
    {
        $scoped = $this->scopedWith(['live' => false, 'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret']]);

        self::assertSame('test_id', $scoped->getClientId());
    }

    public function testGetClientSecret_whenLive_readsFromLiveClient(): void
    {
        $scoped = $this->scopedWith(['live' => true, 'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret']]);

        self::assertSame('live_secret', $scoped->getClientSecret());
    }

    public function testGetClientId_whenNoClientConfigStored_returnsEmptyString(): void
    {
        $scoped = $this->scopedWith(['live' => false]);

        self::assertSame('', $scoped->getClientId());
    }

    public function testGetPublicKeyId_readsHfIdentifier(): void
    {
        $scoped = $this->scopedWith([PayPlugGatewayFactory::HF_IDENTIFIER => 'hf_ident_123']);

        self::assertSame('hf_ident_123', $scoped->getPublicKeyId());
    }

    public function testGetPublicKeyValue_returnsEmptyString(): void
    {
        $scoped = $this->scopedWith([]);

        self::assertSame('', $scoped->getPublicKeyValue());
    }

    public function testGet_readsArbitraryKeyFromConfig(): void
    {
        $scoped = $this->scopedWith(['payplug_webhook_authorization_header' => 'Bearer shared-secret']);

        self::assertSame('Bearer shared-secret', $scoped->get('payplug_webhook_authorization_header'));
    }

    public function testGet_whenKeyMissing_returnsNull(): void
    {
        $scoped = $this->scopedWith([]);

        self::assertNull($scoped->get('missing_key'));
    }

    public function testSet_mergesTheKeyIntoConfigAndFlushesTheScopedConfig(): void
    {
        $gatewayConfig = $this->gatewayConfig(['existing' => 'value']);
        $gatewayConfig->expects(self::once())->method('setConfig')
            ->with(['existing' => 'value', 'new_key' => 'new_value']);
        $this->entityManager->expects(self::once())->method('flush');

        $this->configurationRepository->withGatewayConfig($gatewayConfig)->set('new_key', 'new_value');
    }
}
