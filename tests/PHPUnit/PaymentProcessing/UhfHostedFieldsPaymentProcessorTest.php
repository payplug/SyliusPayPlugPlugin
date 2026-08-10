<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\UnifiedApiHostedPaymentServiceFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\UhfHostedFieldsPaymentProcessor;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * UnifiedApiHostedPaymentServiceFactory is `final` (same constraint documented in
 * UnifiedApiHostedPaymentServiceFactoryTest), so this test builds a real instance of it, backed by
 * mocked seams: IUnifiedApiHttpClient (its postJson() response drives whatever
 * UnifiedApiHostedPaymentService::createHostedPayment() synchronously returns) and a mocked
 * ITokenCache pre-populated with a cached token (so the OAuth2Client/IOAuthHttpClient
 * token-exchange path is never actually exercised).
 */
final class UhfHostedFieldsPaymentProcessorTest extends TestCase
{
    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private UhfHostedFieldsPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->unifiedApiHttpClient = $this->createMock(IUnifiedApiHttpClient::class);
        $tokenCache = $this->createMock(ITokenCache::class);
        $tokenCache->method('get')->willReturn('cached-token');
        $oauthHttpClient = $this->createMock(IOAuthHttpClient::class);
        $oauthHttpClient->expects(self::never())->method('post');
        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://api-qa.payplug.com', '', '', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);
        $hostedPaymentServiceFactory = new UnifiedApiHostedPaymentServiceFactory(
            $this->unifiedApiHttpClient,
            $tokenManager,
            'https://api-qa.payplug.com',
        );

        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->processor = new UhfHostedFieldsPaymentProcessor(
            $hostedPaymentServiceFactory,
            $this->urlGenerator,
            $this->logger,
        );
    }

    /** @return array{0: PaymentInterface&MockObject, 1: PaymentMethodInterface&MockObject} */
    private function buildPayment(): array
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'test_client' => ['client_id' => 'test_id', 'client_secret' => 'test_secret', 'account_id' => 'acc_123'],
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('uhf_code');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(42);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $payment->method('getDetails')->willReturn([]);

        return [$payment, $method];
    }

    // -------------------------------------------------------------------------
    // Happy path — 3DS redirect required
    // -------------------------------------------------------------------------

    public function testProcess_withRedirectRequired_storesPaymentIdAndRedirectUrl(): void
    {
        [$payment] = $this->buildPayment();

        $this->urlGenerator->method('generate')
            ->with('sylius_payment_method_notify', ['code' => 'uhf_code'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://shop.example.com/payment-methods/uhf_code')
        ;

        $this->unifiedApiHttpClient->expects(self::once())
            ->method('postJson')
            ->with(
                'https://api-qa.payplug.com/payments',
                [
                    'account' => ['id' => 'acc_123'],
                    'amount' => 1000,
                    'currency' => 'EUR',
                    'orderId' => '42',
                    'capture' => true,
                    'hfToken' => 'hf_token_abc',
                    'paymentMethod' => ['details' => ['selectedBrand' => 'VISA']],
                    'notificationUrl' => 'https://shop.example.com/payment-methods/uhf_code',
                ],
            )
            ->willReturn([
                'status' => 201,
                'body' => \json_encode(['id' => 'pay_upc_1', 'redirect' => ['url' => 'https://secure.payplug.com/3ds/pay_upc_1']]),
            ])
        ;

        $payment->expects(self::once())->method('setDetails')->with([
            'status' => PaymentInterface::STATE_PROCESSING,
            'payment_id' => 'pay_upc_1',
            'redirect_url' => 'https://secure.payplug.com/3ds/pay_upc_1',
            'hosted_fields_selected_brand' => 'VISA',
            'hosted_fields_save_card' => true,
        ]);

        $this->processor->process($payment, 'hf_token_abc', 'VISA', true);
    }

    // -------------------------------------------------------------------------
    // Happy path — no 3DS challenge, synchronous success
    // -------------------------------------------------------------------------

    public function testProcess_withNoRedirect_storesPaymentIdWithoutRedirectUrl(): void
    {
        [$payment] = $this->buildPayment();

        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 200,
            'body' => \json_encode(['id' => 'pay_upc_2']),
        ]);

        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static fn (array $details): bool => 'pay_upc_2' === $details['payment_id'] &&
                null === $details['redirect_url'],
        ));

        $this->processor->process($payment, 'hf_token_abc', 'CB', false);
    }

    // -------------------------------------------------------------------------
    // Unified API call fails (non-2xx) — error recorded, nothing thrown
    // -------------------------------------------------------------------------

    public function testProcess_whenApiCallFails_storesErrorWithoutPaymentId(): void
    {
        [$payment] = $this->buildPayment();

        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 500,
            'body' => '{"error":"internal"}',
        ]);

        $this->logger->expects(self::once())->method('error');
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static fn (array $details): bool => isset($details['error']),
        ));

        $this->processor->process($payment, 'hf_token_abc', 'CB', false);
    }

    // -------------------------------------------------------------------------
    // DTO fails vendor-side validation before any network call — same handling
    // -------------------------------------------------------------------------

    public function testProcess_whenHfTokenIsEmpty_storesErrorWithoutCallingTheApi(): void
    {
        [$payment] = $this->buildPayment();

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->logger->expects(self::once())->method('error');
        $payment->expects(self::once())->method('setDetails')->with(['error' => 'hfToken must not be empty.']);

        $this->processor->process($payment, '', 'CB', false);
    }

    // -------------------------------------------------------------------------
    // No payment method — cannot resolve merchant credentials
    // -------------------------------------------------------------------------

    public function testProcess_whenPaymentMethodIsNull_throwsLogicException(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->processor->process($payment, 'hf_token_abc', 'CB', false);
    }
}
