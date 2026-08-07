<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\UnifiedApiHostedPaymentServiceFactory;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\CapturePaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\Payment\HfTokenProvider;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * UnifiedApiHostedPaymentServiceFactory and HfTokenProvider are both `final`, so they cannot be
 * mocked directly (same constraint documented in UnifiedApiHostedPaymentServiceFactoryTest). This
 * test instead builds real instances of both, backed by mocked seams: IUnifiedApiHttpClient (its
 * postJson() response drives whatever UnifiedApiHostedPaymentService::createHostedPayment()
 * synchronously returns) and a mocked ITokenCache pre-populated with a cached token (so the
 * OAuth2Client/IOAuthHttpClient token-exchange path is never actually exercised).
 */
final class CapturePaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PayPlugPaymentDataCreator&MockObject $paymentDataCreator;

    private UrlProviderInterface&MockObject $afterPayUrlProvider;

    private LoggerInterface&MockObject $logger;

    private IUnifiedApiHttpClient&MockObject $unifiedApiHttpClient;

    private RequestStack&MockObject $requestStack;

    private SessionInterface&MockObject $session;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private CapturePaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->paymentDataCreator = $this->createMock(PayPlugPaymentDataCreator::class);
        $this->afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

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

        $this->requestStack = $this->createMock(RequestStack::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->requestStack->method('getSession')->willReturn($this->session);
        $hfTokenProvider = new HfTokenProvider($this->requestStack);

        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturn('https://shop.example/notify');

        $this->handler = new CapturePaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->apiClientFactory,
            $this->paymentDataCreator,
            $this->afterPayUrlProvider,
            $this->logger,
            $hostedPaymentServiceFactory,
            $hfTokenProvider,
            $this->urlGenerator,
        );
    }

    // -------------------------------------------------------------------------
    // Legacy gateway path (pre-existing behavior, unaffected by the new UHF branch)
    // -------------------------------------------------------------------------

    public function testInvoke_forLegacyGatewayOnSuccess_storesDetailsAndCompletesTheRequest(): void
    {
        $method = $this->buildMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, []);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->afterPayUrlProvider->method('getUrl')->willReturn('https://shop.example/after-pay');
        $this->paymentDataCreator->method('create')->willReturn(new \ArrayObject(['amount' => 1000]));

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $payplugPayment = Payment::fromAttributes([
            'id' => 'pay_legacy_1',
            'hosted_payment' => ['payment_url' => 'https://payplug.example/pay'],
        ]);
        $client->method('createPayment')->willReturn($payplugPayment);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);

        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static fn (array $details) => 'pay_legacy_1' === $details['payment_id'] &&
                PayPlugApiClientInterface::STATUS_CREATED === $details['status'] &&
                'https://payplug.example/pay' === $details['redirect_url'],
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new CapturePaymentRequest('hash-legacy-1'));
    }

    public function testInvoke_forLegacyGatewayAlreadyCreated_doesNotCallTheClientAgain(): void
    {
        $method = $this->buildMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'factory_name' => PayPlugGatewayFactory::FACTORY_NAME,
            'payment_id' => 'pay_legacy_2',
            'redirect_url' => 'https://payplug.example/pay-2',
        ]);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new CapturePaymentRequest('hash-legacy-2'));
    }

    // -------------------------------------------------------------------------
    // UHF branch — captureViaUnifiedApi()
    // -------------------------------------------------------------------------

    public function testInvoke_forUhfWithRedirectRequired_storesRedirectUrlAndCompletesTheRequest(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, ['hf_token' => 'hf_token_1']);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 201,
            'body' => \json_encode([
                'id' => 'pay_uhf_1',
                'redirect' => ['url' => 'https://3ds.example/redirect'],
            ]),
        ]);

        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static fn (array $details) => 'pay_uhf_1' === $details['payment_id'] &&
                'https://3ds.example/redirect' === $details['redirect_url'] &&
                PayPlugApiClientInterface::STATUS_CREATED === $details['status'] &&
                UhfGatewayFactory::FACTORY_NAME === $details['factory_name'],
        ));

        $paymentRequest->expects(self::once())->method('setResponseData')->with(self::callback(
            static fn (array $data) => 'pay_uhf_1' === $data['payment_id'] &&
                'https://3ds.example/redirect' === $data['redirect_url'],
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new CapturePaymentRequest('hash-uhf-1'));
    }

    public function testInvoke_forUhfWithNoRedirect_completesWithoutRedirectUrl(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, ['hf_token' => 'hf_token_2']);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 201,
            'body' => \json_encode(['id' => 'pay_uhf_2']),
        ]);

        $paymentRequest->expects(self::once())->method('setResponseData')->with(self::callback(
            static fn (array $data) => 'pay_uhf_2' === $data['payment_id'] &&
                null === $data['redirect_url'],
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new CapturePaymentRequest('hash-uhf-2'));
    }

    public function testInvoke_forUhfWithNoHfToken_failsTheRequestWithoutCallingUnifiedApi(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, []);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->session->method('get')->willReturn(null);

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        ($this->handler)(new CapturePaymentRequest('hash-uhf-3'));
    }

    public function testInvoke_whenUnifiedApiThrows_failsTheRequest(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, ['hf_token' => 'hf_token_4']);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->unifiedApiHttpClient->method('postJson')->willReturn([
            'status' => 500,
            'body' => '{"error":"internal"}',
        ]);

        $payment->expects(self::never())->method('setDetails');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        ($this->handler)(new CapturePaymentRequest('hash-uhf-4'));
    }

    public function testInvoke_whenAlreadyCreatedForUhf_doesNotCallUnifiedApiAgain(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'factory_name' => UhfGatewayFactory::FACTORY_NAME,
            'payment_id' => 'pay_uhf_5',
            'redirect_url' => 'https://3ds.example/redirect-5',
        ]);
        $paymentRequest = $this->buildPaymentRequest($payment);

        $this->unifiedApiHttpClient->expects(self::never())->method('postJson');

        $paymentRequest->expects(self::once())->method('setResponseData')->with(self::callback(
            static fn (array $data) => true === $data['retry'] &&
                'pay_uhf_5' === $data['payment_id'] &&
                'https://3ds.example/redirect-5' === $data['redirect_url'],
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new CapturePaymentRequest('hash-uhf-5'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMethod(string $factoryName): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);
        $gatewayConfig->method('getConfig')->willReturn([
            'live' => false,
            'test_client' => [
                'client_id' => 'test_id',
                'client_secret' => 'test_secret',
                'account_id' => 'acc_test',
            ],
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('payplug_method_code');

        return $method;
    }

    /** @param array<string, mixed> $details */
    private function buildPayment(PaymentMethodInterface $method, array $details): PaymentInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(42);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getId')->willReturn(7);

        return $payment;
    }

    private function buildPaymentRequest(PaymentInterface $payment): PaymentRequestInterface&MockObject
    {
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }
}
