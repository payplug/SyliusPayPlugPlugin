<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\ScopedConfigurationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiOperationStatusFetcher;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * TokenManager and OAuth2Client are both `final` (cannot be mocked by PHPUnit), so this test
 * builds real instances of both, controlling behavior at their actual outer boundary instead:
 * the injected IOAuthHttpClient (OAuth2 token endpoint) and ITokenCache (caching) — same pattern
 * as UnifiedApiHostedPaymentCreatorTest.
 */
final class UnifiedApiOperationStatusFetcherTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ITokenCache&MockObject $tokenCache;

    private ScopedConfigurationRepositoryInterface&MockObject $configurationRepository;

    private ScopedConfigurationRepositoryInterface&MockObject $scopedConfiguration;

    private ?string $cachedToken = null;

    private UnifiedApiOperationStatusFetcher $fetcher;

    protected function setUp(): void
    {
        $this->unifiedApiHttpClient = $this->createMock(IUnifiedApiHttpClient::class);
        $this->oauthHttpClient = $this->createMock(IOAuthHttpClient::class);
        $this->tokenCache = $this->createMock(ITokenCache::class);
        $this->scopedConfiguration = $this->scopedConfigurationWith('client_abc', 'secret_xyz');
        $this->configurationRepository = $this->createMock(ScopedConfigurationRepositoryInterface::class);
        $this->configurationRepository->method('forPaymentMethod')
            ->willReturnCallback(fn (): ScopedConfigurationRepositoryInterface => $this->scopedConfiguration);

        $oauth2Client = new OAuth2Client($this->oauthHttpClient, 'https://api.payplug.com', '', '', 'https://www.payplug.com');
        $tokenManager = new TokenManager($this->tokenCache, $oauth2Client);

        $this->fetcher = new UnifiedApiOperationStatusFetcher(
            $this->unifiedApiHttpClient,
            $tokenManager,
            $this->configurationRepository,
            'https://api.payplug.com',
        );
    }

    private function scopedConfigurationWith(
        string $clientId,
        string $clientSecret,
    ): ScopedConfigurationRepositoryInterface&MockObject
    {
        $scoped = $this->createMock(ScopedConfigurationRepositoryInterface::class);
        $scoped->method('getClientId')->willReturn($clientId);
        $scoped->method('getClientSecret')->willReturn($clientSecret);

        return $scoped;
    }

    /**
     * An operation id alone does not identify an account. Both call sites poll for a specific
     * payment, so they pass that payment's method and the fetch is authenticated against the
     * account the operation actually lives on.
     */
    public function testGetOperation_signsWithTheCredentialsOfThePaymentMethodsOwnAccount(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $this->scopedConfiguration = $this->scopedConfigurationWith('de_id', 'de_secret');

        $scopedFor = null;
        $this->configurationRepository->expects(self::once())
            ->method('forPaymentMethod')
            ->willReturnCallback(function (PaymentMethodInterface $m) use (&$scopedFor): ScopedConfigurationRepositoryInterface {
                $scopedFor = $m;

                return $this->scopedConfiguration;
            });

        $sentCredentials = null;
        $this->oauthHttpClient->method('post')->willReturnCallback(
            function (string $url, array $formParams, array $headers = []) use (&$sentCredentials): array {
                $sentCredentials = $headers['Authorization'];

                return ['status' => 200, 'body' => json_encode(['access_token' => 'jwt', 'expires_in' => 300, 'token_type' => 'Bearer'])];
            },
        );
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 200, 'body' => '{}']);

        $this->fetcher->getOperation('op_1', $method);

        self::assertSame($method, $scopedFor);
        self::assertSame('Basic ' . base64_encode('de_id:de_secret'), $sentCredentials);
    }

    public function testGetOperation_withValidCredentials_returnsTheRawResponse(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $body = '{"id":"op_1","execCode":"0000","orderId":"000000072","amount":7400}';
        $this->unifiedApiHttpClient->method('get')
            ->with('https://api.payplug.com/processing-operations/operations/public/op_1', ['Authorization' => 'Bearer cached-jwt'])
            ->willReturn(['status' => 200, 'body' => $body]);

        $response = $this->fetcher->getOperation('op_1', $this->createMock(PaymentMethodInterface::class));

        self::assertSame(['status' => 200, 'body' => $body], $response);
    }

    /**
     * Unlike the old (deleted) UnifiedApiOperationService, an unknown operation id is not given
     * its own exception type here — getOperation() folds a 404 into the same generic ApiException
     * as any other non-2xx status, since no caller currently needs to tell them apart.
     */
    public function testGetOperation_onMissingOperation_throwsApiException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 404, 'body' => '{}']);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);

        $this->fetcher->getOperation('op_1', $this->createMock(PaymentMethodInterface::class));
    }

    public function testGetOperation_onNon2xxResponse_throwsApiException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->fetcher->getOperation('op_1', $this->createMock(PaymentMethodInterface::class));
    }
}
