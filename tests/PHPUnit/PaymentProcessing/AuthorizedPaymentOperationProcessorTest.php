<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\Exception\Payment\AuthorizationOperationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AuthorizedPaymentOperationProcessor;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationOperatorInterface;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\PartialCancellationNotAllowedException;
use PayplugUnifiedCore\Exceptions\PaymentNotCapturableException;
use PayplugUnifiedCore\Output\CancellationOutput;
use PayplugUnifiedCore\Output\CaptureOutput;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;

final class AuthorizedPaymentOperationProcessorTest extends TestCase
{
    private AuthorizationOperatorInterface&MockObject $operator;

    private ILock&MockObject $lock;

    private StateMachineInterface&MockObject $stateMachine;

    private MockClock $clock;

    private AuthorizedPaymentOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->operator = $this->createMock(AuthorizationOperatorInterface::class);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->stateMachine->method('can')->willReturn(true);
        $this->clock = new MockClock('2026-09-24T10:00:00+00:00');

        $this->processor = new AuthorizedPaymentOperationProcessor(
            $this->operator,
            $this->lock,
            $this->stateMachine,
            $this->clock,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testCapture_partial_recordsTheOperationAndKeepsThePaymentAuthorized(): void
    {
        $payment = $this->authorizedPayment(1000);

        $this->operator->expects(self::once())->method('capture')
            ->with(self::isInstanceOf(PaymentMethod::class), 'pay_1', '42', 300)
            ->willReturn($this->captureOutput('op_c1'));
        $this->stateMachine->expects(self::never())->method('apply');

        $this->processor->capture($payment, 300, 0);

        $authorization = AuthorizationDetails::fromDetails($payment->getDetails());
        self::assertSame(300, $authorization->capturedAmount());
        self::assertSame(700, $authorization->remainingAmount());
        self::assertSame(['operation' => 'capture', 'amount' => 300], $authorization->findOperation('op_c1'));
    }

    public function testCapture_ofTheRemainder_sendsTheExactRemainderAndCompletesThePayment(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 300));

        // Never "no amount": the Unified API reads that as the ORIGINAL authorized amount and
        // refuses it as a duplicate once part of it has been captured.
        $this->operator->expects(self::once())->method('capture')
            // Second operation on this authorization: numbered so Payplug doesn't see a duplicate.
            ->with(self::anything(), 'pay_1', '42', 700, 'EUR', 2)
            ->willReturn($this->captureOutput('op_c2'));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);

        $this->processor->capture($payment, null, 1);

        self::assertSame(0, AuthorizationDetails::fromDetails($payment->getDetails())->remainingAmount());
    }

    public function testCancel_ofTheRestAfterAPartialCancellation_sendsTheExactRemainder(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), AuthorizationDetails::OPERATION_CANCELLATION, 'op_v1', 400));

        $this->operator->expects(self::once())->method('cancel')
            ->with(self::anything(), 'pay_1', '42', 600)
            ->willReturn(new CancellationOutput(200, '{"execCode":"0000","operationIds":["op_v2"]}', null, null));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);

        $this->processor->cancel($payment, null, 1);
    }

    public function testCapture_takesTheDeadlineUpcReturns(): void
    {
        $payment = $this->authorizedPayment(1000);
        $this->operator->method('capture')->willReturn(
            new CaptureOutput(200, '{"operationIds":["op_c1"]}', 300, 1000, '2026-09-30T00:00:00+00:00'),
        );

        $this->processor->capture($payment, 300);

        self::assertSame('2026-09-30T00:00:00+00:00', $payment->getDetails()[AuthorizationDetails::MAX_CAPTURE_DATE]);
    }

    public function testCapture_aboveTheRemainder_isRefusedWithoutCallingUpc(): void
    {
        $payment = $this->authorizedPayment(1000);
        $this->operator->expects(self::never())->method('capture');

        $exception = $this->catchRefusal(fn () => $this->processor->capture($payment, 1001));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.amount_exceeds_remaining', $exception->getTranslationKey());
        self::assertSame(['%remaining%' => '10.00 EUR'], $exception->getTranslationParameters());
    }

    public function testCapture_withAStaleVersion_isRefusedSoAReplayedFormCannotCaptureTwice(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 300));
        $this->operator->expects(self::never())->method('capture');

        $exception = $this->catchRefusal(fn () => $this->processor->capture($payment, 300, 0));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.stale', $exception->getTranslationKey());
    }

    public function testCapture_whileAnotherOperationHoldsTheLock_isRefused(): void
    {
        $lock = $this->createMock(ILock::class);
        $lock->method('acquire')->with('payplug_upc_authorization_42', 30)->willReturn(false);
        $processor = new AuthorizedPaymentOperationProcessor($this->operator, $lock, $this->stateMachine, $this->clock, $this->createMock(LoggerInterface::class));
        $this->operator->expects(self::never())->method('capture');

        $exception = $this->catchRefusal(fn () => $processor->capture($this->authorizedPayment(1000), 300));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.in_progress', $exception->getTranslationKey());
    }

    public function testCapture_afterTheDeadline_isRefusedWithoutCallingUpc(): void
    {
        $payment = $this->authorizedPayment(1000, '2026-09-24T09:00:00+00:00');
        $this->operator->expects(self::never())->method('capture');

        $exception = $this->catchRefusal(fn () => $this->processor->capture($payment, null));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.expired', $exception->getTranslationKey());
        self::assertFalse($this->processor->canCapture($payment));
    }

    public function testCapture_onAPaymentNotAuthorized_isRefused(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        $exception = $this->catchRefusal(fn () => $this->processor->capture($payment, null));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.not_authorized', $exception->getTranslationKey());
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function upcRefusals(): iterable
    {
        yield 'expired' => [new AuthorizationExpiredException('Authorization expired'), 'expired'];
        yield 'not capturable' => [new PaymentNotCapturableException('Payment is not capturable'), 'not_operable'];
        // Staging, 2026-09-24: every capture after the first on this account → HTTP 200 + 4011.
        yield 'second capture' => [new MultipleCaptureNotAllowedException('Duplicate request.'), 'multiple_capture_not_allowed'];
        yield 'generic' => [new ApiException('HTTP 500'), 'api_error'];
    }

    /**
     * @dataProvider upcRefusals
     */
    public function testCapture_refusedByUpc_leavesDetailsAndStateUntouched(
        \Throwable $upcException,
        string $reason,
    ): void
    {
        $payment = $this->authorizedPayment(1000);
        $detailsBefore = $payment->getDetails();
        $this->operator->method('capture')->willThrowException($upcException);
        $this->stateMachine->expects(self::never())->method('apply');
        $this->lock->expects(self::once())->method('release')->with('payplug_upc_authorization_42');

        $exception = $this->catchRefusal(fn () => $this->processor->capture($payment, null));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.' . $reason, $exception->getTranslationKey());
        self::assertSame($detailsBefore, $payment->getDetails());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
    }

    public function testCancel_full_sendsNoAmountAndCancelsThePayment(): void
    {
        $payment = $this->authorizedPayment(1000);

        $this->operator->expects(self::once())->method('cancel')
            ->with(self::anything(), 'pay_1', '42', null)
            ->willReturn(new CancellationOutput(200, '{"operationIds":["op_v1"]}', 1000, 1000));
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);

        $this->processor->cancel($payment, null, 0);

        self::assertSame(1000, AuthorizationDetails::fromDetails($payment->getDetails())->cancelledAmount());
    }

    public function testCancel_partial_keepsTheRestCapturable(): void
    {
        $payment = $this->authorizedPayment(1000);

        $this->operator->expects(self::once())->method('cancel')
            ->with(self::anything(), 'pay_1', '42', 400)
            ->willReturn(new CancellationOutput(200, '{"operationIds":["op_v1"]}', 400, 1000));
        $this->stateMachine->expects(self::never())->method('apply');

        $this->processor->cancel($payment, 400);

        self::assertSame(600, AuthorizationDetails::fromDetails($payment->getDetails())->remainingAmount());
        self::assertTrue($this->processor->canCapture($payment));
    }

    public function testCancel_partialNotAllowedByTheContract_isExplained(): void
    {
        $payment = $this->authorizedPayment(1000);
        $this->operator->method('cancel')->willThrowException(new PartialCancellationNotAllowedException('not enabled'));

        $exception = $this->catchRefusal(fn () => $this->processor->cancel($payment, 400));

        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.partial_cancellation_not_allowed', $exception->getTranslationKey());
    }

    public function testCancel_afterACapture_isNeitherOfferedNorSent(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 300));
        $this->operator->expects(self::never())->method('cancel');

        self::assertFalse($this->processor->canCancel($payment));
        self::assertTrue($this->processor->canCapture($payment));

        $exception = $this->catchRefusal(fn () => $this->processor->cancel($payment, null));
        self::assertSame('payplug_sylius_payplug_plugin.admin.authorization.error.cancel_after_capture', $exception->getTranslationKey());
    }

    public function testOnCompleteTransition_capturesWhatIsStillAuthorized(): void
    {
        $payment = $this->authorizedPayment(1000);
        $this->operator->expects(self::once())->method('capture')
            ->with(self::anything(), 'pay_1', '42', 1000, 'EUR')
            ->willReturn($this->captureOutput('op_c1'));
        // Already inside the "complete" transition: must not apply it a second time.
        $this->stateMachine->expects(self::never())->method('apply');

        $this->processor->onCompleteTransition($this->transitionEvent($payment));

        self::assertSame(1000, AuthorizationDetails::fromDetails($payment->getDetails())->capturedAmount());
    }

    public function testOnCompleteTransition_isANoOpOnceNothingIsLeftToCapture(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), AuthorizationDetails::OPERATION_CAPTURE, 'op_c1', 1000));
        $this->operator->expects(self::never())->method('capture');

        $this->processor->onCompleteTransition($this->transitionEvent($payment));
    }

    public function testOnCompleteTransition_ignoresAnImmediateCapturePayment(): void
    {
        $payment = $this->authorizedPayment(1000);
        $payment->setDetails(['hosted_fields_payment_id' => 'pay_1']);
        $this->operator->expects(self::never())->method('capture');

        $this->processor->onCompleteTransition($this->transitionEvent($payment));
    }

    public function testOnCompleteTransition_abortsTheTransitionWhenTheCaptureIsRefused(): void
    {
        $payment = $this->authorizedPayment(1000);
        $this->operator->method('capture')->willThrowException(new ApiException('HTTP 500'));

        $this->expectException(AuthorizationOperationException::class);

        $this->processor->onCompleteTransition($this->transitionEvent($payment));
    }

    private function authorizedPayment(int $amount, ?string $maxCaptureDate = '2026-09-30T10:00:00+00:00'): Payment
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setConfig([
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_1',
            PayPlugGatewayFactory::DEFERRED_CAPTURE => true,
        ]);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        // Sylius's core Payment asserts it has an order; one without a number yet makes the
        // orderId sent to UPC fall back to the payment id ("42").
        $payment->setOrder(new Order());
        $payment->setMethod($method);
        $payment->setAmount($amount);
        $payment->setCurrencyCode('EUR');
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);
        $payment->setDetails(AuthorizationDetails::open(
            ['hosted_fields_payment_id' => 'pay_1'],
            new PaymentOutput(201, '{}', null, null, null, $maxCaptureDate, $amount),
            $amount,
        ));

        return $payment;
    }

    private function captureOutput(string $operationId): CaptureOutput
    {
        return new CaptureOutput(200, (string) \json_encode(['operationIds' => [$operationId]]), null, null, null);
    }

    private function transitionEvent(PaymentInterface $payment): TransitionEvent
    {
        return new TransitionEvent($payment, new Marking([PaymentInterface::STATE_AUTHORIZED => 1]));
    }

    private function catchRefusal(\Closure $operation): AuthorizationOperationException
    {
        try {
            $operation();
        } catch (AuthorizationOperationException $exception) {
            return $exception;
        }

        self::fail('Expected an AuthorizationOperationException.');
    }
}
