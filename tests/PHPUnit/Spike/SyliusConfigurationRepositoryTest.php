<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Spike;

use PayPlug\SyliusPayPlugPlugin\Spike\SyliusConfigurationRepository;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

final class SyliusConfigurationRepositoryTest extends TestCase
{
    private GatewayConfigInterface&MockObject $gatewayConfig;

    protected function setUp(): void
    {
        $this->gatewayConfig = $this->createMock(GatewayConfigInterface::class);
    }

    public function testGet_liveMode_readsFromLiveClientScope(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'live-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        self::assertSame('live-id', $this->repository()->get('client_id'));
    }

    public function testGet_testMode_readsFromTestClientScope(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'live_client' => ['client_id' => 'live-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        self::assertSame('test-id', $this->repository()->get('client_id'));
    }

    public function testGet_missingKey_returnsNull(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true, 'live_client' => []]);

        self::assertNull($this->repository()->get('client_id'));
    }

    public function testSet_writesIntoActiveScopeAndPersistsWholeConfig(): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'old-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        $this->gatewayConfig->expects(self::once())->method('setConfig')->with([
            'live' => true,
            'live_client' => ['client_id' => 'new-id'],
            'test_client' => ['client_id' => 'test-id'],
        ]);

        $this->repository()->set('client_id', 'new-id');
    }

    /**
     * @dataProvider provideCredentialGetters
     */
    public function testCredentialGetters_present_returnValue(string $method, string $configKey): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => [$configKey => 'the-value'],
        ]);

        self::assertSame('the-value', $this->repository()->{$method}());
    }

    /**
     * @dataProvider provideCredentialGetters
     *
     * The exception message must name the missing key and the factory, never a credential
     * value — there is nothing sensitive to redact here since the value is simply absent, but
     * this locks the message shape so a future edit can't start interpolating $value instead.
     */
    public function testCredentialGetters_missing_throwsApiExceptionWithoutLeakingSecrets(
        string $method,
        string $configKey,
    ): void
    {
        $this->gatewayConfig->method('getConfig')->willReturn(['live' => true, 'live_client' => []]);
        $this->gatewayConfig->method('getFactoryName')->willReturn('payplug');

        try {
            $this->repository()->{$method}();
            self::fail('Expected ApiException to be thrown.');
        } catch (ApiException $exception) {
            self::assertSame(\sprintf('Missing "%s" in gateway configuration "payplug".', $configKey), $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideCredentialGetters(): array
    {
        return [
            'clientId' => ['getClientId', 'client_id'],
            'clientSecret' => ['getClientSecret', 'client_secret'],
            'publicKeyId' => ['getPublicKeyId', 'public_key_id'],
            'publicKeyValue' => ['getPublicKeyValue', 'public_key_value'],
        ];
    }

    private function repository(): SyliusConfigurationRepository
    {
        return new SyliusConfigurationRepository($this->gatewayConfig);
    }
}
