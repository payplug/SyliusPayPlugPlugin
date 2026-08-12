<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiHostedPaymentCreator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TokenManager and OAuth2Client are both `final` (cannot be mocked by PHPUnit), so this test
 * builds real instances of both, controlling behavior at their actual outer boundary instead:
 * the injected IOAuthHttpClient (OAuth2 token endpoint) and ITokenCache (caching) — same pattern
 * as tests/PHPUnit/ApiClient/PayPlugApiClientFactoryTest.php.
 */
final class UnifiedApiHostedPaymentCreatorTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ITokenCache&MockObject $tokenCache;

    private IConfigurationRepository&MockObject $configurationRepository;

    private UnifiedApiHostedPaymentCreator $creator;

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

        $this->creator = new UnifiedApiHostedPaymentCreator(
            $this->unifiedApiHttpClient,
            $tokenManager,
            $this->configurationRepository,
            'https://api.payplug.com',
        );
    }

    private function dto(): HostedFieldDto
    {
        return new HostedFieldDto(new CommonFieldsDto('acct_123', 1000, 'eur', '42'), 'hf_token_abc');
    }

    public function testCreateHostedPayment_withValidCredentials_returnsTheOutput(): void
    {
        $this->tokenCache->method('get')->willReturn(null);
        $this->oauthHttpClient->method('post')->willReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 201, 'body' => '{"id":"pay_1"}']);

        $output = $this->creator->createHostedPayment($this->dto());

        self::assertSame(201, $output->status);
        self::assertNull($output->redirectUrl);
    }

    public function testCreateHostedPayment_withPending3ds_extractsTheRedirectUrl(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 200,
            'body' => json_encode(['id' => 'pay_1', 'redirect' => ['url' => 'https://3ds.payplug.com/challenge']]),
        ]);

        $output = $this->creator->createHostedPayment($this->dto());

        self::assertSame('https://3ds.payplug.com/challenge', $output->redirectUrl);
    }

    public function testCreateHostedPayment_onNon2xxResponse_throwsApiException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->creator->createHostedPayment($this->dto());
    }
}
