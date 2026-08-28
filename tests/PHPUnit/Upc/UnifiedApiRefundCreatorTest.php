<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiRefundCreator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
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

    private IConfigurationRepository&MockObject $configurationRepository;

    private UnifiedApiRefundCreator $creator;

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

        $this->creator = new UnifiedApiRefundCreator(
            $this->unifiedApiHttpClient,
            $tokenManager,
            $this->configurationRepository,
            'https://api.payplug.com',
        );

        $this->tokenCache->method('get')->willReturn('cached-jwt');
    }

    public function testCreateRefund_withoutAmount_sendsAFullRefundUsingTheMethodsOwnAccountId(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', 'submerchant_123');

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'submerchantExternalId' => 'submerchant_123',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'],
            )
            ->willReturn(['status' => 200, 'body' => '{"execCode":"0000"}']);

        $result = $this->creator->createRefund($method, 'pay_123', 'order_1');

        self::assertSame(['status' => 200, 'body' => '{"execCode":"0000"}'], $result);
    }

    public function testCreateRefund_withAmount_sendsAPartialRefund(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', 'submerchant_123');

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'submerchantExternalId' => 'submerchant_123',
                    'amount' => 500,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'],
            )
            ->willReturn(['status' => 200, 'body' => '{}']);

        $this->creator->createRefund($method, 'pay_123', 'order_1', 500);
    }

    public function testCreateRefund_onA404Response_throwsPaymentNotFoundException(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', 'submerchant_123');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 404, 'body' => '{}']);

        $this->expectException(PaymentNotFoundException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    public function testCreateRefund_onNon2xxResponse_throwsApiException(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', 'submerchant_123');
        $this->unifiedApiHttpClient->method('postJson')->willReturn(['status' => 500, 'body' => '{}']);

        $this->expectException(ApiException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    public function testCreateRefund_withANonPositiveAmount_throwsRefundAmountExceptionBeforeAnyNetworkCall(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', 'submerchant_123');
        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->expectException(RefundAmountException::class);

        $this->creator->createRefund($method, 'pay_123', 'order_1', 0);
    }

    /**
     * Same guard CaptureHostedPaymentRequestHandler::resolveGatewayCredentials() already applies
     * at payment-creation time — a blank submerchant id must fail fast and locally rather than
     * round-tripping to the Unified API for a 400 ("subMerchantExternalId is missing") that's
     * indistinguishable from any other malformed-request cause once wrapped in ApiException.
     */
    public function testCreateRefund_withNoConfiguredSubmerchantId_throwsLogicExceptionBeforeAnyNetworkCall(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('acct_123', '');

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Hosted Fields account id or submerchant id is not configured for this payment method.');

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    /**
     * Credentials must come from $method's own gateway config, not from whichever
     * Hosted-Fields-configured payment method IConfigurationRepository's backing store happens to
     * resolve first — otherwise a merchant with more than one such payment method could have a
     * refund routed to the wrong account/submerchant.
     */
    public function testCreateRefund_withNoConfiguredAccountId_throwsLogicExceptionBeforeAnyNetworkCall(): void
    {
        $method = $this->buildHostedFieldsPaymentMethod('', 'submerchant_123');

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Hosted Fields account id or submerchant id is not configured for this payment method.');

        $this->creator->createRefund($method, 'pay_123', 'order_1');
    }

    private function buildHostedFieldsPaymentMethod(
        string $accountId,
        string $submerchantExternalId,
    ): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => $accountId,
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => $submerchantExternalId,
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }
}
