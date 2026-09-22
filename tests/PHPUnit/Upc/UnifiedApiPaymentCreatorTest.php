<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\ScopedConfigurationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiPaymentCreator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * TokenManager and OAuth2Client are both `final` (cannot be mocked by PHPUnit), so this test
 * builds real instances of both, controlling behavior at their actual outer boundary instead:
 * the injected IOAuthHttpClient (OAuth2 token endpoint) and ITokenCache (caching) — same pattern
 * as tests/PHPUnit/ApiClient/PayPlugApiClientFactoryTest.php.
 */
final class UnifiedApiPaymentCreatorTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ITokenCache&MockObject $tokenCache;

    private ScopedConfigurationRepositoryInterface&MockObject $configurationRepository;

    /** What forPaymentMethod() hands back; a test wanting different credentials reassigns it. */
    private ScopedConfigurationRepositoryInterface&MockObject $scopedConfiguration;

    private UnifiedApiPaymentCreator $creator;

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

        $this->creator = new UnifiedApiPaymentCreator(
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
     * The payload DTO carries no account context, so the payment method is what tells the creator
     * which PayPlug account to sign with. Since PRE-3628 two CB payment methods on different
     * channels can hold different credentials, so reading them off an unscoped repository would
     * create the payment on whichever account Doctrine happened to return.
     */
    public function testCreatePayment_signsWithTheCredentialsOfThePaymentMethodsOwnAccount(): void
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

        $this->tokenCache->method('get')->willReturn(null); // force a token request
        $sentCredentials = null;
        $this->oauthHttpClient->method('post')->willReturnCallback(
            function (string $url, array $formParams, array $headers = []) use (&$sentCredentials): array {
                $sentCredentials = $headers['Authorization'];

                return ['status' => 200, 'body' => json_encode(['access_token' => 'jwt', 'expires_in' => 300, 'token_type' => 'Bearer'])];
            },
        );
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 201, 'body' => '{"id":"pay_1"}']);

        $this->creator->createPayment($this->dto(), $method);

        self::assertSame($method, $scopedFor);
        self::assertSame('Basic ' . base64_encode('de_id:de_secret'), $sentCredentials);
    }

    public function testCreateHostedPayment_withValidCredentials_returnsTheOutput(): void
    {
        $this->tokenCache->method('get')->willReturn(null);
        $this->oauthHttpClient->method('post')->willReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 201, 'body' => '{"id":"pay_1"}']);

        $output = $this->creator->createPayment($this->dto(), $this->createMock(PaymentMethodInterface::class));

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

        $output = $this->creator->createPayment($this->dto(), $this->createMock(PaymentMethodInterface::class));

        self::assertSame('https://3ds.payplug.com/challenge', $output->redirectUrl);
    }

    public function testCreateHostedPayment_onNon2xxResponse_throwsApiException(): void
    {
        $this->tokenCache->method('get')->willReturn('cached-jwt');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->creator->createPayment($this->dto(), $this->createMock(PaymentMethodInterface::class));
    }
}
