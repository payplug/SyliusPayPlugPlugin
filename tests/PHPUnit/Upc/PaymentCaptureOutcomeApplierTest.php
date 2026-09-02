<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Command\PaymentCaptureFlow;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentCaptureOutcomeApplier;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

final class PaymentCaptureOutcomeApplierTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private StateMachineInterface&MockObject $stateMachine;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private PaymentCaptureOutcomeApplier $applier;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);

        $this->applier = new PaymentCaptureOutcomeApplier($this->logger, $this->stateMachine, $this->orderStateMutator);
    }

    public function testFailPaymentRequest_logsSetsResponseDataAndAppliesFailTransition(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);

        $this->logger->expects(self::once())->method('error')
            ->with(self::stringContains('Hosted payment creation failed.'), self::anything());
        $paymentRequest->expects(self::once())->method('setResponseData')->with(['error' => 'boom']);
        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->applier->failPaymentRequest($paymentRequest, $payment, new \LogicException('boom'), PaymentCaptureFlow::Hosted);
    }

    public function testApplyOutcome_withRedirectHtml_storesItAndNeverAppliesOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $output = new PaymentOutput(201, '{"id":"pay_1","execCode":"0001"}', null, '<form>3ds</form>', null);

        $paymentRequest->expects(self::once())->method('setResponseData')->with(['redirect_html' => '<form>3ds</form>']);
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->applier->applyOutcome($paymentRequest, $payment, $output);
    }

    public function testApplyOutcome_withRedirectUrl_storesItAndNeverAppliesOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $output = new PaymentOutput(201, '{"id":"pay_1"}', 'https://example.com/3ds', null, null);

        $paymentRequest->expects(self::once())->method('setResponseData')->with(['redirect_url' => 'https://example.com/3ds']);
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->applier->applyOutcome($paymentRequest, $payment, $output);
    }

    public function testApplyOutcome_withDirectSuccessExecCode_appliesPaidOutcomeToOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $output = new PaymentOutput(201, '{"id":"pay_1","execCode":"0000"}', null, null, null);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->applier->applyOutcome($paymentRequest, $payment, $output);
    }

    public function testApplyOutcome_withNoExecCodeInResponseBody_neverAppliesOrderStateMutator(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $output = new PaymentOutput(201, '{"id":"pay_1"}', null, null, null);

        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->applier->applyOutcome($paymentRequest, $payment, $output);
    }
}
