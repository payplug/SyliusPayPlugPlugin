<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\NotifyPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
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
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    private PaymentRequestProviderInterface&MockObject $paymentRequestProvider;

    private StateMachineInterface&MockObject $stateMachine;

    private ILock&MockObject $lock;

    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

    private NotifyPaymentRequestHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRequestProvider = $this->createMock(PaymentRequestProviderInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->lock = $this->createMock(ILock::class);
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->configurationRepository->method('get')->with('payplug_webhook_authorization_header')->willReturn('Bearer shared-secret');

        $this->handler = new NotifyPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->createMock(PayPlugApiClientFactoryInterface::class),
            $this->createMock(PaymentNotificationHandler::class),
            $this->createMock(RefundNotificationHandler::class),
            $this->createMock(PaymentTransitionApplier::class),
            $this->lock,
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function uhfPaymentRequest(
        string $rawBody,
        array $headers = ['Authorization' => ['Bearer shared-secret']],
    ): PaymentRequestInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(UhfGatewayFactory::FACTORY_NAME);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn([
            'http_request' => ['content' => $rawBody, 'headers' => $headers],
        ]);

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testInvoke_forUhfWithAValidNotification_locksSavesMutatesAndMarksTreated(): void
    {
        $body = \json_encode(['id' => 'op_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $paymentRequest = $this->uhfPaymentRequest($body);

        $this->lock->expects(self::once())->method('acquire')->with('payplug_uhf_operation_op_1', 30)->willReturn(true);
        $this->paymentRepository->method('isTreated')->with('op_1')->willReturn(false);
        $this->paymentRepository->expects(self::once())->method('save')->with(self::callback(
            static fn ($data): bool => 'op_1' === $data->operationId && PaymentOutcome::PAID === $data->outcome,
        ));
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_1');
        $this->lock->expects(self::once())->method('release')->with('payplug_uhf_operation_op_1');

        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)
        ;

        ($this->handler)(new NotifyPaymentRequest($paymentRequest->getId()));
    }

    // -------------------------------------------------------------------------
    // Idempotency — already treated, no re-mutation
    // -------------------------------------------------------------------------

    public function testInvoke_whenAlreadyTreated_doesNotSaveOrMutateAgain(): void
    {
        $body = \json_encode(['id' => 'op_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $paymentRequest = $this->uhfPaymentRequest($body);

        $this->lock->method('acquire')->willReturn(true);
        $this->paymentRepository->method('isTreated')->willReturn(true);
        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->lock->expects(self::once())->method('release');

        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)
        ;

        ($this->handler)(new NotifyPaymentRequest($paymentRequest->getId()));
    }

    // -------------------------------------------------------------------------
    // Idempotency — lock already held (concurrent webhook retry), skip gracefully
    // -------------------------------------------------------------------------

    public function testInvoke_whenLockAlreadyHeld_skipsWithoutFailingTheRequest(): void
    {
        $body = \json_encode(['id' => 'op_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $paymentRequest = $this->uhfPaymentRequest($body);

        $this->lock->method('acquire')->willReturn(false);
        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->lock->expects(self::never())->method('release');

        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)
        ;

        ($this->handler)(new NotifyPaymentRequest($paymentRequest->getId()));
    }

    // -------------------------------------------------------------------------
    // Signature verification failure
    // -------------------------------------------------------------------------

    public function testInvoke_withWrongAuthorizationHeader_failsTheRequestWithoutLocking(): void
    {
        $body = \json_encode(['id' => 'op_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $paymentRequest = $this->uhfPaymentRequest($body, ['Authorization' => ['Bearer wrong-secret']]);

        $this->lock->expects(self::never())->method('acquire');

        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL)
        ;

        ($this->handler)(new NotifyPaymentRequest($paymentRequest->getId()));
    }

    // -------------------------------------------------------------------------
    // Malformed body
    // -------------------------------------------------------------------------

    public function testInvoke_withMalformedBody_failsTheRequest(): void
    {
        $paymentRequest = $this->uhfPaymentRequest('not json');

        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL)
        ;

        ($this->handler)(new NotifyPaymentRequest($paymentRequest->getId()));
    }
}
