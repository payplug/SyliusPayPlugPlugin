<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use Payplug\Resource\Payment as PayplugResourcePayment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\NotifyPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PaymentNotificationHandler&MockObject $paymentNotificationHandler;

    private RefundNotificationHandler&MockObject $refundNotificationHandler;

    private PaymentTransitionApplier&MockObject $paymentTransitionApplier;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private LoggerInterface&MockObject $logger;

    private NotifyPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->paymentNotificationHandler = $this->createMock(PaymentNotificationHandler::class);
        $this->refundNotificationHandler = $this->createMock(RefundNotificationHandler::class);
        $this->paymentTransitionApplier = $this->createMock(PaymentTransitionApplier::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new NotifyPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->apiClientFactory,
            $this->paymentNotificationHandler,
            $this->refundNotificationHandler,
            $this->paymentTransitionApplier,
            $this->orderStateMutator,
            $this->logger,
        );
    }

    public function testInvoke_knownStatus_callsOrderStateMutatorWithMappedOutcome(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CAPTURED, 42);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_unknownStatus_doesNotCallOrderStateMutator(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CANCELED, 42);

        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_orderStateMutatorThrows_isCaughtAndDoesNotPreventCompletion(): void
    {
        $this->prepareNormalFlow(PayPlugApiClientInterface::STATUS_CAPTURED, 42);

        $this->orderStateMutator->method('apply')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects(self::once())->method('warning');

        // The outer flow must still complete normally: TRANSITION_COMPLETE, not TRANSITION_FAIL.
        $this->stateMachine->expects(self::once())->method('apply')->with(
            self::isInstanceOf(PaymentRequestInterface::class),
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_paymentAlreadyCompleted_shortCircuitsAndSkipsOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_COMPLETED);
        $method = $this->createMock(PaymentMethodInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '{}']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $resource = $this->createMock(PayplugResourcePayment::class);
        $client->method('treat')->willReturn($resource);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);

        $this->paymentTransitionApplier->expects(self::never())->method('apply');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    public function testInvoke_invalidPayload_failsWithoutCallingOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $paymentRequest->expects(self::once())->method('setResponseData');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        $this->handler->__invoke(new NotifyPaymentRequest('hash'));
    }

    private function prepareNormalFlow(string $status, int $orderId): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn($orderId);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_NEW);
        $payment->method('getDetails')->willReturn(['status' => $status]);
        $payment->method('getOrder')->willReturn($order);
        $method = $this->createMock(PaymentMethodInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => ['content' => '{}']]);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $client = $this->createMock(PayPlugApiClientInterface::class);
        $resource = $this->createMock(PayplugResourcePayment::class);
        $client->method('treat')->willReturn($resource);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);
    }
}
