<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiOperationStatusFetcher;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    private IConfigurationRepository&MockObject $configurationRepository;

    private UnifiedApiOperationStatusFetcher $fetcher;

    protected function setUp(): void
    {
        $this->unifiedApiHttpClient = $this->createMock(IUnifiedApiHttpClient::class);
        $this->oauthHttpClient = $this->createMock(IOAuthHttpClient::class);
        $this->tokenCache = $this->createMock(ITokenCache::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->configurationRepository->method('getClientId')->willReturn('client_abc');
        $this->configurationRepository->method('getClientSecret')->willReturn('secret_xyz');

        $oauth2Client = new OAuth2Client($this->oauthHttpClient, 'https://api.payplug.com', '', '', 'https://www.payplug.com');
        $tokenManager = new TokenManager($this->tokenCache, $oauth2Client);

        $this->fetcher = new UnifiedApiOperationStatusFetcher(
            $this->unifiedApiHttpClient,
            $tokenManager,
            $this->configurationRepository,
            'https://api.payplug.com',
        );
    }

    public function testGetOperation_withValidCredentials_returnsTheRawResponse(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $body = '{"id":"op_1","transaction":{"status":{"execCode":"0000"}}}';
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 200, 'body' => $body]);

        $response = $this->fetcher->getOperation('op_1');

        self::assertSame(['status' => 200, 'body' => $body], $response);
    }

    public function testGetOperation_onMissingOperation_throwsOperationNotFoundException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 404, 'body' => '{}']);

        $this->expectException(OperationNotFoundException::class);

        $this->fetcher->getOperation('op_1');
    }

    public function testGetOperation_onNon2xxResponse_throwsApiException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('get')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->fetcher->getOperation('op_1');
    }
}
