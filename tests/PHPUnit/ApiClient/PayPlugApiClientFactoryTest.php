<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\ApiClient;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface as CorePaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * TokenManager and OAuth2Client are both `final` (cannot be mocked by PHPUnit), so this test
 * builds real instances of both, controlling behavior at their actual outer boundary instead:
 * the injected IOAuthHttpClient (HTTP transport) and ITokenCache (caching).
 */
final class PayPlugApiClientFactoryTest extends TestCase
{
    private RepositoryInterface&MockObject $gatewayConfigRepository;

    private CacheInterface&MockObject $cache;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ITokenCache&MockObject $tokenCache;

    private PayPlugApiClientFactory $factory;

    protected function setUp(): void
    {
        $this->gatewayConfigRepository = $this->createMock(RepositoryInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->oauthHttpClient = $this->createMock(IOAuthHttpClient::class);
        $this->tokenCache = $this->createMock(ITokenCache::class);

        $oauth2Client = new OAuth2Client($this->oauthHttpClient, 'https://api-qa.payplug.com', '', '', 'https://www.payplug.com');
        $tokenManager = new TokenManager($this->tokenCache, $oauth2Client);

        $this->factory = new PayPlugApiClientFactory($this->gatewayConfigRepository, $this->cache, $tokenManager);
    }

    // -------------------------------------------------------------------------
    // create() / createForPaymentMethod() — happy path, token freshly fetched
    // -------------------------------------------------------------------------

    public function testCreateForPaymentMethod_withValidClientCredentials_returnsApiClient(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($this->buildGatewayConfig(isLive: false));

        $this->tokenCache->method('get')->willReturn(null); // cache miss
        $this->oauthHttpClient->method('post')->willReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);

        $client = $this->factory->createForPaymentMethod($paymentMethod);

        self::assertInstanceOf(PayPlugApiClientInterface::class, $client);
    }

    public function testCreate_withNoGatewayConfigFound_throwsLogicException(): void
    {
        $this->gatewayConfigRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->factory->create('payplug');
    }

    // -------------------------------------------------------------------------
    // getTokenForGatewayConfig() — missing client config
    // -------------------------------------------------------------------------

    public function testCreateForPaymentMethod_withNoClientConfigForCurrentMode_throwsGatewayConfigurationException(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['live' => true]); // no 'live_client' key
        $gatewayConfig->method('getFactoryName')->willReturn('payplug');
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(GatewayConfigurationException::class);
        $this->expectExceptionMessage('No client config found for payplug');

        $this->factory->createForPaymentMethod($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // getTokenForGatewayConfig() — client config present but missing client_id/client_secret
    // -------------------------------------------------------------------------

    /**
     * A present-but-incomplete client config (e.g. `client_secret` missing) must be rejected
     * before any HTTP call is made — otherwise it reaches the token endpoint with an empty
     * credential and a genuine misconfiguration gets reported as a connectivity failure instead.
     */
    public function testCreateForPaymentMethod_withEmptyClientSecret_throwsGatewayConfigurationExceptionWithoutCallingTokenEndpoint(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'test_client' => ['client_id' => 'client_test'], // no 'client_secret' key
        ]);
        $gatewayConfig->method('getFactoryName')->willReturn('payplug');
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->oauthHttpClient->expects(self::never())->method('post');

        $this->expectException(GatewayConfigurationException::class);
        $this->expectExceptionMessage('No client config found for payplug');

        $this->factory->createForPaymentMethod($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // getTokenForGatewayConfig() — token endpoint failure wrapped as GatewayConfigurationException
    // -------------------------------------------------------------------------

    /**
     * TokenManager -> OAuth2Client throws ApiException on a non-2xx response; the factory must
     * catch it and rethrow as GatewayConfigurationException (never leak the vendor exception type).
     */
    public function testCreateForPaymentMethod_whenTokenEndpointRejectsCredentials_wrapsFailureAsGatewayConfigurationException(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($this->buildGatewayConfig(isLive: true));

        $this->tokenCache->method('get')->willReturn(null);
        $this->oauthHttpClient->method('post')->willReturn(['status' => 401, 'body' => '{"error":"invalid_client"}']);

        $this->expectException(GatewayConfigurationException::class);
        $this->expectExceptionMessage('Unable to connect to PayPlug API. Please check your credentials in the PayPlug plugin configuration.');

        $this->factory->createForPaymentMethod($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // getTokenForGatewayConfig() — a cached token is reused, no HTTP call made
    // -------------------------------------------------------------------------

    public function testCreateForPaymentMethod_withCachedToken_doesNotCallTheTokenEndpoint(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($this->buildGatewayConfig(isLive: false));

        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->oauthHttpClient->expects(self::never())->method('post');

        $this->factory->createForPaymentMethod($paymentMethod);
    }

    // -------------------------------------------------------------------------
    // createForPaymentMethod() — credentials are scoped to the payment method, not the factory name
    // -------------------------------------------------------------------------

    /**
     * Since PRE-3628 several enabled gateway configs may share a factory name — one per channel.
     * `findOneBy(['factoryName' => ...])` then resolves to an arbitrary one of them, so a client
     * built that way can sign a request for channel A with channel B's account credentials.
     * createForPaymentMethod() must read the credentials off the payment method's own gateway
     * config and never consult the repository; routing it back through that lookup is the
     * production change that makes this test fail.
     */
    public function testCreateForPaymentMethod_withTwoChannelsSharingAFactoryName_usesEachChannelsOwnCredentials(): void
    {
        $frConfig = $this->buildGatewayConfig(isLive: false, clientId: 'client_fr', clientSecret: 'secret_fr');
        $deConfig = $this->buildGatewayConfig(isLive: false, clientId: 'client_de', clientSecret: 'secret_de');

        // Both rows are enabled and carry factoryName 'payplug', so Doctrine is free to return
        // either one; the FR row stands in for "whichever one it picked".
        $this->gatewayConfigRepository->method('findOneBy')->willReturn($frConfig);

        $this->tokenCache->method('get')->willReturn(null); // cache miss for both client ids

        /** @var list<string> $sentCredentials */
        $sentCredentials = [];
        $this->oauthHttpClient->method('post')->willReturnCallback(
            function (string $url, array $formParams, array $headers = []) use (&$sentCredentials): array {
                $sentCredentials[] = $headers['Authorization'];

                return [
                    'status' => 200,
                    'body' => json_encode(['access_token' => 'jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
                ];
            },
        );

        $this->factory->createForPaymentMethod($this->buildPaymentMethodOnChannel('FR', $frConfig));
        $this->factory->createForPaymentMethod($this->buildPaymentMethodOnChannel('DE', $deConfig));

        self::assertSame([
            'Basic ' . base64_encode('client_fr:secret_fr'),
            'Basic ' . base64_encode('client_de:secret_de'),
        ], $sentCredentials);
    }

    private function buildPaymentMethodOnChannel(
        string $channelCode,
        GatewayConfigInterface $gatewayConfig,
    ): PaymentMethodInterface&MockObject {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($channelCode);

        $paymentMethod = $this->createMock(CorePaymentMethodInterface::class);
        $paymentMethod->method('isEnabled')->willReturn(true);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $paymentMethod;
    }

    private function buildGatewayConfig(
        bool $isLive,
        string $clientId = 'client',
        string $clientSecret = 'secret',
    ): GatewayConfigInterface&MockObject {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => $isLive,
            'live_client' => ['client_id' => $clientId, 'client_secret' => $clientSecret],
            'test_client' => ['client_id' => $clientId, 'client_secret' => $clientSecret],
        ]);
        $gatewayConfig->method('getFactoryName')->willReturn('payplug');

        return $gatewayConfig;
    }
}
