<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use ArrayObject;
use Payplug\Exception\BadRequestException;
use Payplug\Resource\Payment as PayplugPayment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\CapturePaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefreshResult;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproRefusalClassifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CapturePaymentRequestHandlerTest extends TestCase
{
    private const COUNTRY_REFUSAL = '{"object":"error","message":"The parameters you sent are invalid.","details":{"billing":{"country":"Not available for this country."}}}';

    private const POSTCODE_REFUSAL = '{"object":"error","message":"The parameters you sent are invalid.","details":{"billing":{"postcode":"Invalid postcode."}}}';

    private const RETRY_URL = 'https://shop.test/en_US/order/token123';

    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private PayPlugApiClientInterface&MockObject $client;

    private PproConfigurationRefresherInterface&MockObject $refresher;

    private FinalUrlProviderInterface&MockObject $finalUrlProvider;

    private LoggerInterface&MockObject $logger;

    private RequestStack $requestStack;

    private CapturePaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->client = $this->createMock(PayPlugApiClientInterface::class);
        $this->refresher = $this->createMock(PproConfigurationRefresherInterface::class);
        $this->finalUrlProvider = $this->createMock(FinalUrlProviderInterface::class);
        $this->finalUrlProvider->method('getUrl')->willReturn(self::RETRY_URL);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = new RequestStack();

        $clientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $clientFactory->method('createForPaymentMethod')->willReturn($this->client);

        $dataCreator = $this->createMock(PayPlugPaymentDataCreator::class);
        $dataCreator->method('create')->willReturn(new ArrayObject(['amount' => 1000, 'currency' => 'EUR']));

        $afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $afterPayUrlProvider->method('getUrl')->willReturn('https://shop.test/after-pay/hash');

        $this->handler = new CapturePaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $clientFactory,
            $dataCreator,
            $afterPayUrlProvider,
            $this->logger,
            $this->refresher,
            new PproRefusalClassifier(),
            $this->finalUrlProvider,
            $this->requestStack,
        );
    }

    public function testCountryRefusalOnPproRefreshesAndRedirectsToOrderPage(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::COUNTRY_REFUSAL, 400));
        // The bus's doctrine_transaction middleware flushes once the handler returns.
        $this->refresher->expects(self::once())->method('refresh')->with($paymentRequest->getMethod(), false)->willReturn(new PproRefreshResult(true, true));
        $this->finalUrlProvider->expects(self::once())->method('getUrl')->with($paymentRequest->getPayment());
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::never())->method('error');

        ($this->handler)(new CapturePaymentRequest('hash'));

        $data = $paymentRequest->getResponseData();
        self::assertSame(self::RETRY_URL, $data['redirect_url']);
        self::assertSame('payplug_sylius_payplug_plugin.error.ppro_not_available', $data['error']);
        self::assertSame('error', $data['object']);
    }

    public function testAmountRefusalOnPproIsHandledTheSameWay(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', '{"details":{"amount":"Too low."}}', 400));
        $this->refresher->expects(self::once())->method('refresh')->willReturn(new PproRefreshResult(true));
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame(self::RETRY_URL, $paymentRequest->getResponseData()['redirect_url']);
    }

    public function testFlashIsAddedWhenASessionExists(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::COUNTRY_REFUSAL, 400));
        $this->refresher->method('refresh')->willReturn(new PproRefreshResult(true));

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame(
            ['payplug_sylius_payplug_plugin.error.ppro_not_available'],
            $request->getSession()->getFlashBag()->peek('error'),
        );
    }

    public function testNoFlashAndNoErrorWithoutASession(): void
    {
        $this->requestStack->push(new Request());
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::COUNTRY_REFUSAL, 400));
        $this->refresher->method('refresh')->willReturn(new PproRefreshResult(true));

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame(self::RETRY_URL, $paymentRequest->getResponseData()['redirect_url']);
    }

    public function testRefresherFailureStillRedirects(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::COUNTRY_REFUSAL, 400));
        $this->refresher->method('refresh')->willReturn(PproRefreshResult::notRefreshed());
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame(self::RETRY_URL, $paymentRequest->getResponseData()['redirect_url']);
    }

    public function testOtherHttpExceptionKeepsTodaysBehaviour(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::POSTCODE_REFUSAL, 400));
        $this->refresher->expects(self::never())->method('refresh');
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->logger->expects(self::once())->method('error');

        ($this->handler)(new CapturePaymentRequest('hash'));

        $data = $paymentRequest->getResponseData();
        self::assertArrayNotHasKey('redirect_url', $data);
        self::assertArrayNotHasKey('error', $data);
        self::assertSame(['billing' => ['postcode' => 'Invalid postcode.']], $data['details']);
    }

    public function testNonPproFactoryNeverRefreshes(): void
    {
        $paymentRequest = $this->paymentRequest(ApplePayGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad request', self::COUNTRY_REFUSAL, 400));
        $this->refresher->expects(self::never())->method('refresh');
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertArrayNotHasKey('redirect_url', $paymentRequest->getResponseData());
    }

    /**
     * A refusal whose body is not JSON (a proxy error page, an empty body) still fails the payment
     * request cleanly instead of breaking on the response data.
     */
    public function testNonJsonRefusalBodyFailsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willThrowException(new BadRequestException('Bad gateway', '<html>502</html>', 502));
        $this->refresher->expects(self::never())->method('refresh');
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_FAIL);

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame([], $paymentRequest->getResponseData());
    }

    public function testHappyPathUnchanged(): void
    {
        $paymentRequest = $this->paymentRequest(WeroGatewayFactory::FACTORY_NAME);
        $this->client->method('createPayment')->willReturn(PayplugPayment::fromAttributes([
            'id' => 'pay_123',
            'hosted_payment' => ['payment_url' => 'https://secure.payplug.com/pay/123'],
        ]));
        $this->refresher->expects(self::never())->method('refresh');
        $this->expectTransition(PaymentRequestTransitions::TRANSITION_COMPLETE);

        ($this->handler)(new CapturePaymentRequest('hash'));

        self::assertSame('https://secure.payplug.com/pay/123', $paymentRequest->getResponseData()['redirect_url']);
        self::assertSame('pay_123', $paymentRequest->getPayment()->getDetails()['payment_id']);
    }

    private function expectTransition(string $transition): void
    {
        $this->stateMachine->expects(self::once())->method('apply')->with(
            self::isInstanceOf(PaymentRequest::class),
            PaymentRequestTransitions::GRAPH,
            $transition,
        );
    }

    private function paymentRequest(string $factoryName): PaymentRequest
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);

        $method = new PaymentMethod();
        $method->setCode($factoryName);
        $method->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setMethod($method);
        $payment->setAmount(1000);
        $payment->setCurrencyCode('EUR');

        $paymentRequest = new PaymentRequest($payment, $method);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }
}
