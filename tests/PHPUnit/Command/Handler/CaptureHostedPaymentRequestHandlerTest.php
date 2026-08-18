<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\CaptureHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Upc\HostedPaymentCreatorInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Output\HostedPaymentOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class CaptureHostedPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private HostedPaymentCreatorInterface&MockObject $hostedPaymentCreator;

    private UrlProviderInterface&MockObject $afterPayUrlProvider;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private RequestStack&MockObject $requestStack;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private CaptureHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->hostedPaymentCreator = $this->createMock(HostedPaymentCreatorInterface::class);
        $this->afterPayUrlProvider = $this->createMock(UrlProviderInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);

        $this->handler = new CaptureHostedPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->hostedPaymentCreator,
            $this->afterPayUrlProvider,
            $this->urlGenerator,
            $this->logger,
            $this->requestStack,
            $this->orderStateMutator,
        );
    }

    private function paymentRequestWithPayment(
        array $details,
        int $amount = 1000,
        string $currency = 'EUR',
        ?array $gatewayConfig = ['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1'],
    ): PaymentRequestInterface&MockObject
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        if (null !== $gatewayConfig) {
            $config = $this->createMock(GatewayConfigInterface::class);
            $config->method('getConfig')->willReturn($gatewayConfig);
            $method->method('getGatewayConfig')->willReturn($config);
        }

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn('customer@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn($currency);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    public function testInvoke_onDirectSuccess_completesThePaymentRequestWithoutARedirect(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment([
            'hosted_fields_token' => 'hf_token_abc',
            'hosted_fields_selected_brand' => 'VISA',
        ]);

        $this->hostedPaymentCreator->method('createHostedPayment')->willReturn(new HostedPaymentOutput(201, '{"id":"pay_1"}', null));

        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => !isset($data['redirect_url'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new CaptureHostedPaymentRequest($paymentRequest->getId()));
    }

    public function testInvoke_whenNoHostedFieldsTokenStored_throws(): void
    {
        $this->paymentRequestWithPayment([]);

        $this->expectException(\LogicException::class);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenGatewayConfigIsMissingAccountOrSubmerchantId_throws(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc'], gatewayConfig: null);

        $this->expectException(\LogicException::class);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onApiException_failsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->hostedPaymentCreator->method('createHostedPayment')->willThrowException(new ApiException('boom'));

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_whenCustomerEmailIsMissing_failsThePaymentRequestInsteadOfCallingHostedPaymentCreator(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn(['hfIdentifier' => 'acct_123', 'hfSubMerchantId' => 'sub_ext_1']);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn(null);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getDetails')->willReturn(['hosted_fields_token' => 'hf_token_abc']);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(1000);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getHash')->willReturn(Uuid::v4());
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $this->hostedPaymentCreator->expects(self::never())->method('createHostedPayment');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(self::callback(static fn (array $data): bool => isset($data['error'])));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithoutExecCode_neverAppliesOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->hostedPaymentCreator->method('createHostedPayment')->willReturn(new HostedPaymentOutput(201, '{"id":"pay_1"}', null));

        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithSuccessExecCode_appliesPaidOutcomeToOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->hostedPaymentCreator->method('createHostedPayment')
            ->willReturn(new HostedPaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null));

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onDirectSuccessWithFailureExecCode_appliesFailedOutcomeToOrderStateMutator(): void
    {
        $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->hostedPaymentCreator->method('createHostedPayment')
            ->willReturn(new HostedPaymentOutput(201, '{"id":"pay_1","execCode":"9999"}', null));

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::FAILED);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }

    public function testInvoke_onRedirectOutcome_neverAppliesOrderStateMutator(): void
    {
        $paymentRequest = $this->paymentRequestWithPayment(['hosted_fields_token' => 'hf_token_abc']);

        $this->hostedPaymentCreator->method('createHostedPayment')
            ->willReturn(new HostedPaymentOutput(201, '{"id":"pay_1"}', 'https://example.com/3ds'));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $paymentRequest->expects(self::once())->method('setResponseData')
            ->with(['redirect_url' => 'https://example.com/3ds']);

        $this->handler->__invoke(new CaptureHostedPaymentRequest(null));
    }
}
