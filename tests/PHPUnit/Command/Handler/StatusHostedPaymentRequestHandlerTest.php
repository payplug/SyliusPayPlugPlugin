<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\Handler\StatusHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

final class StatusHostedPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private OperationStatusFetcherInterface&MockObject $operationStatusFetcher;

    private HostedFieldsWebhookNotificationHandler&MockObject $webhookNotificationHandler;

    private LoggerInterface&MockObject $logger;

    private StatusHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->operationStatusFetcher = $this->createMock(OperationStatusFetcherInterface::class);
        $this->webhookNotificationHandler = $this->createMock(HostedFieldsWebhookNotificationHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new StatusHostedPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->operationStatusFetcher,
            $this->webhookNotificationHandler,
            $this->logger,
        );
    }

    /** @param mixed[] $details */
    private function paymentRequest(
        string $state = PaymentInterface::STATE_PROCESSING,
        array $details = [],
    ): PaymentRequestInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn($state);
        $payment->method('getDetails')->willReturn($details);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    public function testInvoke_withNoForcedStatus_andPaymentAlreadyResolved_skipsPollingAndCompletesRequest(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_COMPLETED, ['hosted_fields_operation_id' => 'op_123']);

        $this->operationStatusFetcher->expects(self::never())->method('getOperation');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withNoForcedStatus_andNoOperationIdStored_skipsPollingAndCompletesRequest(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_PROCESSING, []);

        $this->operationStatusFetcher->expects(self::never())->method('getOperation');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withNoForcedStatus_andFinalExecCode_appliesOutcomeViaWebhookHandler(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_PROCESSING, ['hosted_fields_operation_id' => 'op_123']);
        $payment = $paymentRequest->getPayment();
        $body = json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000072', 'amount' => 7400]);

        $this->operationStatusFetcher->expects(self::once())->method('getOperation')->with('op_123')
            ->willReturn(['status' => 200, 'body' => $body]);
        $this->webhookNotificationHandler->expects(self::once())->method('treat')->with($payment, $body, []);

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withNoForcedStatus_andPendingExecCode_stillDelegatesToWebhookHandler(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_PROCESSING, ['hosted_fields_operation_id' => 'op_123']);
        $payment = $paymentRequest->getPayment();
        $body = json_encode(['id' => 'op_123', 'execCode' => '0001', 'orderId' => '000000072', 'amount' => 7400]);

        $this->operationStatusFetcher->method('getOperation')->willReturn(['status' => 200, 'body' => $body]);
        $this->webhookNotificationHandler->expects(self::once())->method('treat')->with($payment, $body, []);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withNoForcedStatus_whenFetcherFails_logsAndStillCompletesRequest(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_PROCESSING, ['hosted_fields_operation_id' => 'op_123']);

        $this->operationStatusFetcher->method('getOperation')->willThrowException(new ApiException('boom'));
        $this->webhookNotificationHandler->expects(self::never())->method('treat');
        $this->logger->expects(self::once())->method('error');

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withNoForcedStatus_whenWebhookHandlerRejectsThePayload_logsAndStillCompletesRequest(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_PROCESSING, ['hosted_fields_operation_id' => 'op_123']);
        $body = json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000072', 'amount' => 7400]);

        $this->operationStatusFetcher->method('getOperation')->willReturn(['status' => 200, 'body' => $body]);
        $this->webhookNotificationHandler->method('treat')->willThrowException(new InvalidNotificationException('mismatch'));
        $this->logger->expects(self::once())->method('error');

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withForcedCanceledStatus_cancelsThePaymentWhenAllowed(): void
    {
        $paymentRequest = $this->paymentRequest();
        $payment = $paymentRequest->getPayment();

        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->operationStatusFetcher->expects(self::never())->method('getOperation');
        $this->stateMachine->expects(self::exactly(2))->method('apply');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }

    public function testInvoke_withForcedCanceledStatus_whenTransitionNotAllowed_stillCompletesThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest();

        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }
}
