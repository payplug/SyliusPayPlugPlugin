<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\ApiClient;

use PayPlug\SyliusPayPlugPlugin\ApiClient\UnifiedApiHostedPaymentServiceFactory;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * TokenManager is `final` (cannot be mocked by PHPUnit, see PayPlugApiClientFactoryTest for
 * precedent), so this test builds a real instance from mocked IOAuthHttpClient/ITokenCache. It is
 * never actually exercised here: AbstractUnifiedApiService only calls it lazily on a real HTTP
 * request, which these factory tests never trigger.
 */
final class UnifiedApiHostedPaymentServiceFactoryTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $httpClient;

    private UnifiedApiHostedPaymentServiceFactory $factory;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(IUnifiedApiHttpClient::class);
        $oauthHttpClient = $this->createMock(IOAuthHttpClient::class);
        $tokenCache = $this->createMock(ITokenCache::class);
        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://api-qa.payplug.com', '', '', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        $this->factory = new UnifiedApiHostedPaymentServiceFactory(
            $this->httpClient,
            $tokenManager,
            'https://api-qa.payplug.com',
        );
    }

    public function testCreateForPaymentMethod_withLiveCredentials_buildsAConfiguredService(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret', 'account_id' => 'acc_live'],
            'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret', 'account_id' => 'acc_test'],
        ]);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $service = $this->factory->createForPaymentMethod($method);

        self::assertInstanceOf(UnifiedApiHostedPaymentService::class, $service);
    }

    public function testCreateForPaymentMethod_whenNotLive_usesTestCredentials(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret', 'account_id' => 'acc_test'],
        ]);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $service = $this->factory->createForPaymentMethod($method);

        self::assertInstanceOf(UnifiedApiHostedPaymentService::class, $service);
    }

    public function testCreateForPaymentMethod_whenClientConfigMissing_throwsGatewayConfigurationException(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['live' => true]);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(GatewayConfigurationException::class);

        $this->factory->createForPaymentMethod($method);
    }

    public function testCreateForPaymentMethod_whenAccountIdMissing_throwsGatewayConfigurationException(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => true,
            'live_client' => ['client_id' => 'live_id', 'client_secret' => 'live_secret'],
        ]);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(GatewayConfigurationException::class);

        $this->factory->createForPaymentMethod($method);
    }

    public function testCreateForPaymentMethod_whenNoGatewayConfig_throwsLogicException(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->factory->createForPaymentMethod($method);
    }
}
