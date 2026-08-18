<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\Handler\StatusHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Upc\OperationStatusFetcherInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;
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

    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private LoggerInterface&MockObject $logger;

    private StatusHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->operationStatusFetcher = $this->createMock(OperationStatusFetcherInterface::class);
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new StatusHostedPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->operationStatusFetcher,
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->logger,
        );
    }

    private function paymentRequest(
        // Sylius's state machine is never driven to STATE_PROCESSING for this flow in practice —
        // the payment stays STATE_NEW throughout the 3DS-pending window, confirmed against a real
        // QA payment row. STATE_NEW is therefore the realistic default here, not STATE_PROCESSING.
        string $state = PaymentInterface::STATE_NEW,
        array $details = ['hosted_fields_operation_id' => 'op_1', 'hosted_fields_payment_id' => 'pay_1'],
    ): PaymentRequestInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getState')->willReturn($state);
        $payment->method('getDetails')->willReturn($details);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    private static function operationBody(string $execCode): string
    {
        return \json_encode(['id' => 'op_1', 'transaction' => ['status' => ['execCode' => $execCode]]]);
    }

    public function testInvoke_withNoForcedStatus_completesThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest(state: PaymentInterface::STATE_COMPLETED);

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_withForcedCanceledStatus_cancelsThePaymentWhenAllowed(): void
    {
        $paymentRequest = $this->paymentRequest(state: PaymentInterface::STATE_COMPLETED);
        $payment = $paymentRequest->getPayment();

        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->expects(self::exactly(2))->method('apply');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }

    public function testInvoke_withForcedCanceledStatus_whenTransitionNotAllowed_stillCompletesThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest(state: PaymentInterface::STATE_COMPLETED);

        $this->stateMachine->method('can')->willReturn(false);
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }

    public function testInvoke_withForcedCanceledStatus_neverPolls(): void
    {
        $this->paymentRequest(state: PaymentInterface::STATE_PROCESSING);

        $this->stateMachine->method('can')->willReturn(false);
        $this->operationStatusFetcher->expects(self::never())->method('getOperation');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null, 'canceled'));
    }

    public function testInvoke_whenPaymentIsAlreadyResolved_neverPolls(): void
    {
        $this->paymentRequest(state: PaymentInterface::STATE_COMPLETED);

        $this->operationStatusFetcher->expects(self::never())->method('getOperation');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPaymentIsStillProcessing_stillPolls(): void
    {
        $this->paymentRequest(state: PaymentInterface::STATE_PROCESSING);

        $this->operationStatusFetcher->expects(self::once())->method('getOperation')->with('op_1')
            ->willReturn(['status' => 200, 'body' => self::operationBody('0001')]);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenNoHostedFieldsOperationIdStored_neverPolls(): void
    {
        $this->paymentRequest(details: ['hosted_fields_payment_id' => 'pay_1']);

        $this->operationStatusFetcher->expects(self::never())->method('getOperation');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenNoHostedFieldsPaymentIdStored_neverPolls(): void
    {
        $this->paymentRequest(details: ['hosted_fields_operation_id' => 'op_1']);

        $this->operationStatusFetcher->expects(self::never())->method('getOperation');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPollStillPending_neverAppliesAnOutcome(): void
    {
        $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')->with('op_1')
            ->willReturn(['status' => 200, 'body' => self::operationBody('0001')]);

        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->paymentRepository->expects(self::never())->method('markTreated');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPollResolvesToSuccess_appliesPaidOutcomeAndMarksTreated(): void
    {
        $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')->with('op_1')
            ->willReturn(['status' => 200, 'body' => self::operationBody('0000')]);
        $this->paymentRepository->method('isTreated')->with('pay_1')->willReturn(false);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('pay_1');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPollResolvesToFailure_appliesFailedOutcome(): void
    {
        $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')->with('op_1')
            ->willReturn(['status' => 200, 'body' => self::operationBody('9999')]);
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::FAILED);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenAlreadyTreatedByTheWebhook_neverAppliesAnOutcomeAgain(): void
    {
        $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')
            ->willReturn(['status' => 200, 'body' => self::operationBody('0000')]);
        $this->paymentRepository->method('isTreated')->with('pay_1')->willReturn(true);

        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->paymentRepository->expects(self::never())->method('markTreated');

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPollThrowsApiException_neverAppliesAnOutcomeAndStillCompletes(): void
    {
        $paymentRequest = $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')->willThrowException(new ApiException('boom'));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }

    public function testInvoke_whenPollThrowsOperationNotFoundException_neverAppliesAnOutcomeAndStillCompletes(): void
    {
        $paymentRequest = $this->paymentRequest();

        $this->operationStatusFetcher->method('getOperation')->willThrowException(new OperationNotFoundException('gone'));

        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new StatusHostedPaymentRequest(null));
    }
}
