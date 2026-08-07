<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\Handler\NotifyPaymentRequestHandler;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Models\OperationData;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
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

    private ILock&MockObject $lock;

    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

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
        $this->lock = $this->createMock(ILock::class);
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new NotifyPaymentRequestHandler(
            $this->paymentRequestProvider,
            $this->stateMachine,
            $this->apiClientFactory,
            $this->paymentNotificationHandler,
            $this->refundNotificationHandler,
            $this->paymentTransitionApplier,
            $this->lock,
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------------
    // Legacy gateway path (pre-existing behavior, unaffected by the new UHF branch)
    // -------------------------------------------------------------------------

    public function testInvoke_forLegacyGatewayOnSuccess_treatsNotificationAndCompletesTheRequest(): void
    {
        $method = $this->buildMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, ['http_request' => ['content' => '{"id":"pay_1"}']]);

        $payplugPayment = Payment::fromAttributes(['id' => 'pay_1']);
        $client = $this->createMock(PayPlugApiClientInterface::class);
        $client->method('treat')->willReturn($payplugPayment);
        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($client);

        $this->paymentNotificationHandler->expects(self::once())->method('treat');
        $this->refundNotificationHandler->expects(self::once())->method('treat');
        $this->paymentTransitionApplier->expects(self::once())->method('apply')->with($payment);

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-legacy-1'));
    }

    public function testInvoke_forLegacyGatewayWithInvalidPayload_failsTheRequest(): void
    {
        $method = $this->buildMethod(PayPlugGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, ['http_request' => ['content' => '']]);

        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $paymentRequest->expects(self::once())->method('setResponseData');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-legacy-2'));
    }

    // -------------------------------------------------------------------------
    // UHF branch — notifyViaUnifiedApi()
    // -------------------------------------------------------------------------

    public function testInvoke_forUhfWithAValidNotification_locksSavesMutatesAndMarksTreated(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, $this->buildUhfPayload(
            'op_1',
            '0000',
            'order_55',
            1000,
            'expected-secret',
        ));

        $this->configurationRepository->method('get')
            ->with('payplug_webhook_authorization_header')
            ->willReturn('expected-secret');

        $this->lock->expects(self::once())->method('acquire')
            ->with('payplug_uhf_operation_op_1', 30)
            ->willReturn(true);

        $this->paymentRepository->expects(self::once())->method('isTreated')->with('op_1')->willReturn(false);

        $callOrder = [];

        $this->paymentRepository->expects(self::once())->method('save')
            ->with(self::callback(static function (OperationData $data) use (&$callOrder): bool {
                $callOrder[] = 'save';

                return 'op_1' === $data->operationId &&
                    PaymentOutcome::PAID === $data->outcome &&
                    'order_55' === $data->orderId &&
                    1000 === $data->amount;
            }));

        $this->orderStateMutator->expects(self::once())->method('apply')
            ->with('order_55', PaymentOutcome::PAID)
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'apply';
            });

        $this->paymentRepository->expects(self::once())->method('markTreated')
            ->with('op_1')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'markTreated';
            });

        $this->lock->expects(self::once())->method('release')->with('payplug_uhf_operation_op_1');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-uhf-1'));

        self::assertSame(['save', 'apply', 'markTreated'], $callOrder);
    }

    public function testInvoke_whenAlreadyTreated_doesNotSaveOrMutateAgain(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, $this->buildUhfPayload(
            'op_2',
            '0000',
            'order_56',
            2000,
            'expected-secret',
        ));

        $this->configurationRepository->method('get')->willReturn('expected-secret');

        $this->lock->expects(self::once())->method('acquire')
            ->with('payplug_uhf_operation_op_2', 30)
            ->willReturn(true);

        $this->paymentRepository->expects(self::once())->method('isTreated')->with('op_2')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->paymentRepository->expects(self::never())->method('markTreated');

        $this->lock->expects(self::once())->method('release')->with('payplug_uhf_operation_op_2');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-uhf-2'));
    }

    public function testInvoke_whenLockAlreadyHeld_skipsWithoutFailingTheRequest(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, $this->buildUhfPayload(
            'op_3',
            '0000',
            'order_57',
            3000,
            'expected-secret',
        ));

        $this->configurationRepository->method('get')->willReturn('expected-secret');

        $this->lock->expects(self::once())->method('acquire')
            ->with('payplug_uhf_operation_op_3', 30)
            ->willReturn(false);

        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->lock->expects(self::never())->method('release');

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-uhf-3'));
    }

    public function testInvoke_withWrongAuthorizationHeader_failsTheRequestWithoutLocking(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, $this->buildUhfPayload(
            'op_4',
            '0000',
            'order_58',
            4000,
            'wrong-secret',
        ));

        $this->configurationRepository->method('get')
            ->with('payplug_webhook_authorization_header')
            ->willReturn('expected-secret');

        $this->lock->expects(self::never())->method('acquire');
        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $paymentRequest->expects(self::once())->method('setResponseData')->with(self::callback(
            static fn (array $data) => isset($data['error']),
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-uhf-4'));
    }

    public function testInvoke_withMalformedBody_failsTheRequest(): void
    {
        $method = $this->buildMethod(UhfGatewayFactory::FACTORY_NAME);
        $payment = $this->buildPayment($method, [], PaymentInterface::STATE_NEW);
        $paymentRequest = $this->buildPaymentRequest($payment, [
            'http_request' => [
                'content' => '',
                'headers' => ['Authorization' => ['expected-secret']],
            ],
        ]);

        $this->lock->expects(self::never())->method('acquire');
        $this->paymentRepository->expects(self::never())->method('isTreated');

        $paymentRequest->expects(self::once())->method('setResponseData')->with(self::callback(
            static fn (array $data) => isset($data['error']),
        ));

        $this->stateMachine->expects(self::once())->method('apply')->with(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        ($this->handler)(new NotifyPaymentRequest('hash-uhf-5'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMethod(string $factoryName): PaymentMethodInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getCode')->willReturn('payplug_method_code');

        return $method;
    }

    /** @param array<string, mixed> $details */
    private function buildPayment(
        PaymentMethodInterface $method,
        array $details,
        string $state,
    ): PaymentInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getState')->willReturn($state);
        $payment->method('getId')->willReturn(7);

        return $payment;
    }

    /** @param mixed $payload */
    private function buildPaymentRequest(PaymentInterface $payment, $payload): PaymentRequestInterface&MockObject
    {
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getPayload')->willReturn($payload);

        $this->paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        return $paymentRequest;
    }

    /** @return array<string, mixed> */
    private function buildUhfPayload(
        string $operationId,
        string $execCode,
        string $orderId,
        int $amount,
        string $authorizationHeaderValue,
    ): array {
        return [
            'http_request' => [
                'content' => \json_encode([
                    'id' => $operationId,
                    'execCode' => $execCode,
                    'orderId' => $orderId,
                    'amount' => $amount,
                ]),
                'headers' => [
                    'Authorization' => [$authorizationHeaderValue],
                    'Content-Type' => ['application/json'],
                ],
            ],
        ];
    }
}
