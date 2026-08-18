<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\Handler\NotifyHostedPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

final class NotifyHostedPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private ILock&MockObject $lock;

    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

    private LoggerInterface&MockObject $logger;

    private NotifyHostedPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->lock = $this->createMock(ILock::class);
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new NotifyHostedPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->lock,
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->logger,
        );
    }

    private function paymentRequestWithPayload(
        array $httpRequest,
        int $paymentId = 42,
        int $paymentAmount = 1000,
    ): PaymentRequestInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn($paymentId);
        $payment->method('getAmount')->willReturn($paymentAmount);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayload')->willReturn(['http_request' => $httpRequest]);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    public function testInvoke_onValidNotification_savesTreatsAndAppliesTheOutcome(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $paymentRequest = $this->paymentRequestWithPayload([
            'content' => $body,
            'headers' => ['Authorization' => ['Bearer shared-secret']],
        ]);

        $this->configurationRepository->method('get')->with('payplug_webhook_authorization_header')->willReturn('Bearer shared-secret');
        $this->lock->method('acquire')->willReturn(true);
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_123');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);
        $this->lock->expects(self::once())->method('release');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }

    public function testInvoke_whenLockIsHeld_releasesNothingAndCompletesWithoutApplying(): void
    {
        $paymentRequest = $this->paymentRequestWithPayload(['content' => '{}', 'headers' => []]);
        $this->lock->method('acquire')->willReturn(false);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }

    public function testInvoke_whenAlreadyTreated_isIdempotentAndDoesNotReapply(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $this->paymentRequestWithPayload([
            'content' => $body,
            'headers' => ['Authorization' => ['Bearer shared-secret']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->lock->method('acquire')->willReturn(true);
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->lock->expects(self::once())->method('release');

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }

    public function testInvoke_whenOrderIdDoesNotMatchThePaymentRequestsOwnPayment_failsWithoutApplyingTheOutcome(): void
    {
        // The webhook body claims to be about order/payment "999", but the notify hash this
        // request arrived on belongs to a PaymentRequest whose own payment id is 42. Applying the
        // outcome here would mutate the wrong payment.
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '999', 'amount' => 1000]);
        $paymentRequest = $this->paymentRequestWithPayload([
            'content' => $body,
            'headers' => ['Authorization' => ['Bearer shared-secret']],
        ], 42, 1000);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->lock->method('acquire')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->logger->expects(self::once())->method('error');
        $this->lock->expects(self::once())->method('release');
        $paymentRequest->expects(self::once())->method('setResponseData');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }

    public function testInvoke_whenAmountDoesNotMatchThePaymentRequestsOwnPayment_failsWithoutApplyingTheOutcome(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 500]);
        $paymentRequest = $this->paymentRequestWithPayload([
            'content' => $body,
            'headers' => ['Authorization' => ['Bearer shared-secret']],
        ], 42, 1000);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->lock->method('acquire')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->logger->expects(self::once())->method('error');
        $this->lock->expects(self::once())->method('release');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }

    public function testInvoke_onInvalidSignature_logsReleasesTheLockAndFailsThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequestWithPayload([
            'content' => '{}',
            'headers' => ['Authorization' => ['Bearer wrong-secret']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->lock->method('acquire')->willReturn(true);

        $this->logger->expects(self::once())->method('error');
        $this->lock->expects(self::once())->method('release');
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->handler->__invoke(new NotifyHostedPaymentRequest(null));
    }
}
