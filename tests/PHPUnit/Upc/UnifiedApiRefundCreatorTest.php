<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\ScopedConfigurationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiRefundCreator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * TokenManager and OAuth2Client are both `final` (cannot be mocked by PHPUnit), so this test
 * builds real instances of both, controlling behavior at their actual outer boundary instead —
 * same pattern as UnifiedApiHostedPaymentCreatorTest.
 */
final class UnifiedApiRefundCreatorTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private IOAuthHttpClient&MockObject $oauthHttpClient;

    private ITokenCache&MockObject $tokenCache;

    private ScopedConfigurationRepositoryInterface&MockObject $configurationRepository;

    /** What forPaymentMethod() hands back; a test wanting different credentials reassigns it. */
    private ScopedConfigurationRepositoryInterface&MockObject $scopedConfiguration;

    private ?string $cachedToken = 'cached-jwt';

    private UnifiedApiRefundCreator $creator;

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

        $this->creator = new UnifiedApiRefundCreator(
            $this->unifiedApiHttpClient,
            $tokenManager,
            $this->configurationRepository,
            'https://api.payplug.com',
        );

        // Swappable so a test that needs to observe the token request itself can force a cache miss.
        $this->tokenCache->method('get')->willReturnCallback(fn (): ?string => $this->cachedToken);
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
     * createRefund() already routes the *account id* per payment method; the OAuth credentials it
     * signs with have to follow the same method, or a refund on channel B is authenticated as
     * channel A and rejected — or worse, accepted against the wrong merchant.
     */
    public function testCreateRefund_signsWithTheCredentialsOfThePaymentMethodsOwnAccount(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_de');
        $this->scopedConfiguration = $this->scopedConfigurationWith('de_id', 'de_secret');
        $this->cachedToken = null; // force a real token request, so its credentials are observable

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
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 200, 'body' => '{}']);

        $this->creator->createRefund($method, 'pay_123', 'order_1');

        self::assertSame($method, $scopedFor);
        self::assertSame('Basic ' . base64_encode('de_id:de_secret'), $sentCredentials);
    }

    public function testCreateRefund_withoutAmount_sendsAFullRefundUsingTheMethodsOwnAccountId(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'],
            )
            ->willReturn(['status' => 200, 'body' => '{"execCode":"0000"}']);

        $result = $this->creator->createRefund($method, 'pay_123', 'order_1');

        self::assertSame(['status' => 200, 'body' => '{"execCode":"0000"}'], $result);
    }

    public function testCreateRefund_withAmount_sendsAPartialRefund(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'amount' => 500,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'],
            )
            ->willReturn(['status' => 200, 'body' => '{}']);

        $this->creator->createRefund($method, 'pay_123', 'order_1', 500);
    }

    public function testCreateRefund_onA404Response_throwsPaymentNotFoundException(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 404, 'body' => '{}']);

        $this->expectException(PaymentNotFoundException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    public function testCreateRefund_onNon2xxResponse_throwsApiException(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    public function testCreateRefund_withANonPositiveAmount_throwsRefundAmountExceptionBeforeAnyNetworkCall(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');
        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->expectException(RefundAmountException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1', 0);
    }

    /**
     * The refund body must state what $amount's minor units are: without it the Unified API infers
     * the currency from the account, which silently means the wrong thing for a multi-currency
     * merchant.
     */
    public function testCreateRefund_withCurrency_sendsItAlongsideTheAmount(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123');

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'amount' => 6800,
                    'currency' => 'USD',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'],
            )
            ->willReturn(['status' => 200, 'body' => '{}']);

        $this->creator->createRefund($method, 'pay_123', 'order_1', 6800, 'USD');
    }

    /**
     * Credentials must come from $method's own gateway config, not from whichever
     * Hosted-Fields-configured payment method IConfigurationRepository's backing store happens to
     * resolve first — otherwise a merchant with more than one such payment method could have a
     * refund routed to the wrong account.
     */
    public function testCreateRefund_withNoConfiguredAccountId_throwsLogicExceptionBeforeAnyNetworkCall(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('');

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Hosted Fields account id is not configured for this payment method.');

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    private function buildHostedFieldsPaymentMethod(
        string $accountId,
    ): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => $accountId,
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }
}
