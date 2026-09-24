<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\ScopedConfigurationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\UnifiedApiAuthorizationOperator;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\PartialCancellationNotAllowedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Real TokenManager/OAuth2Client (both final), controlled at their outer HTTP boundary — same
 * pattern as UnifiedApiRefundCreatorTest.
 */
final class UnifiedApiAuthorizationOperatorTest extends TestCase
{
    private const AUTH_HEADERS = ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json'];

    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private ScopedConfigurationRepositoryInterface&MockObject $configurationRepository;

    private UnifiedApiAuthorizationOperator $operator;

    protected function setUp(): void
    {
        $this->unifiedApiHttpClient = $this->createMock(IUnifiedApiHttpClient::class);
        $tokenCache = $this->createMock(ITokenCache::class);
        $tokenCache->method('get')->willReturn('cached-jwt');

        $scoped = $this->createMock(ScopedConfigurationRepositoryInterface::class);
        $scoped->method('getClientId')->willReturn('client_abc');
        $scoped->method('getClientSecret')->willReturn('secret_xyz');
        $this->configurationRepository = $this->createMock(ScopedConfigurationRepositoryInterface::class);
        $this->configurationRepository->method('forPaymentMethod')->willReturn($scoped);

        $oauth2Client = new OAuth2Client($this->createMock(IOAuthHttpClient::class), 'https://api.payplug.com', '', '', 'https://www.payplug.com');

        $this->operator = new UnifiedApiAuthorizationOperator(
            $this->unifiedApiHttpClient,
            new TokenManager($tokenCache, $oauth2Client),
            $this->configurationRepository,
            'https://api.payplug.com',
        );
    }

    public function testCapture_partial_postsTheAmountToTheCaptureEndpointOfTheMethodsOwnAccount(): void
    {
        $method = $this->hostedFieldsPaymentMethod('acct_123');
        $this->configurationRepository->expects(self::once())->method('forPaymentMethod')->with($method);

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture #1 for order order_1',
                    'amount' => 300,
                    'currency' => 'USD',
                ],
                self::AUTH_HEADERS,
            )
            ->willReturn(['status' => 200, 'body' => '{"amount":300,"requestedAmount":1000,"operationIds":["op_c1"]}']);

        $output = $this->operator->capture($method, 'pay_123', 'order_1', 300, 'USD');

        self::assertSame(700, $output->remainingCapturableAmount);
    }

    public function testCancel_full_postsAVoidWithoutAmount(): void
    {
        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/void',
                [
                    'account' => ['id' => 'acct_123'],
                    'orderId' => 'order_1',
                    'description' => 'Cancellation #1 for order order_1',
                ],
                self::AUTH_HEADERS,
            )
            ->willReturn(['status' => 200, 'body' => '{"operationIds":["op_v1"]}']);

        $this->operator->cancel($this->hostedFieldsPaymentMethod('acct_123'), 'pay_123', 'order_1', null);
    }

    public function testCancel_partialRefusedByTheContract_surfacesUpcsOwnException(): void
    {
        // The real staging answer (2026-09-24) to a partial void on an account without the option.
        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 403,
            'body' => '{"status":403,"error":"Forbidden","errorCategory":"INVALID_REQUEST","message":"The operation is not allowed."}',
        ]);

        $this->expectException(PartialCancellationNotAllowedException::class);

        $this->operator->cancel($this->hostedFieldsPaymentMethod('acct_123'), 'pay_123', 'order_1', 400, 1, 'USD');
    }

    /**
     * Staging, 2026-09-24: a second capture on the same authorization comes back as HTTP 200 with
     * a failure execCode. UPC must not let that through as a successful CaptureOutput.
     */
    public function testCapture_secondCaptureRefusedWithA200_surfacesMultipleCaptureNotAllowed(): void
    {
        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 200,
            'body' => '{"descriptor":"Plug","execCode":"4011","message":"Duplicate request.","operationIds":["op_dup"]}',
        ]);

        $this->expectException(MultipleCaptureNotAllowedException::class);

        $this->operator->capture($this->hostedFieldsPaymentMethod('acct_123'), 'pay_123', 'order_1', 6201, 'USD', 2);
    }

    public function testCancel_partial_sendsTheCurrencyUpcRequiresAlongsideAnAmount(): void
    {
        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(self::anything(), self::callback(
                static fn (array $body): bool => 1000 === $body['amount'] && 'USD' === $body['currency'],
            ))
            ->willReturn(['status' => 200, 'body' => '{"execCode":"0000","operationIds":["op_v1"]}']);

        $this->operator->cancel($this->hostedFieldsPaymentMethod('acct_123'), 'pay_123', 'order_1', 1000, 1, 'USD');
    }

    private function hostedFieldsPaymentMethod(string $accountId): PaymentMethodInterface&MockObject
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
