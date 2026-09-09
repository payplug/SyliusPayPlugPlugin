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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PaymentCaptureOutcomeApplierTest extends TestCase
{
    private const SHOPPER_ERROR_FLASH_KEY = 'payplug_sylius_payplug_plugin.error.transaction_failed';

    private LoggerInterface&MockObject $logger;

    private StateMachineInterface&MockObject $stateMachine;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private RequestStack $requestStack;

    private Session $session;

    private PaymentCaptureOutcomeApplier $applier;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);

        // A real Session/FlashBag rather than a mock: the assertions below are about what the
        // shopper actually ends up seeing, which is the flash bag's contents.
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);

        $this->applier = $this->createApplier($this->requestStack);
    }

    private function createApplier(RequestStack $requestStack): PaymentCaptureOutcomeApplier
    {
        return new PaymentCaptureOutcomeApplier(
            $this->logger,
            $this->stateMachine,
            $this->orderStateMutator,
            $requestStack,
        );
    }

    public function testFailPaymentRequest_tellsTheShopperTheTransactionDidNotGoThrough(): void
    {
        $this->applier->failPaymentRequest(
            $this->createMock(PaymentRequestInterface::class),
            $this->createMock(PaymentInterface::class),
            new \LogicException('boom'),
            PaymentCaptureFlow::Alias,
        );

        self::assertSame(
            [self::SHOPPER_ERROR_FLASH_KEY],
            $this->session->getFlashBag()->get('error'),
        );
    }

    public function testFailPaymentRequest_neverLeaksTheExceptionMessageToTheShopper(): void
    {
        // Real example from a UPC 403: the raw message names internal infrastructure and the
        // account's configuration, so it must stay in the log and out of the flash bag.
        $leaky = 'The IP address "10.204.92.13" is not allowed to access this account.';

        $this->applier->failPaymentRequest(
            $this->createMock(PaymentRequestInterface::class),
            $this->createMock(PaymentInterface::class),
            new \RuntimeException($leaky),
            PaymentCaptureFlow::Alias,
        );

        self::assertSame([self::SHOPPER_ERROR_FLASH_KEY], $this->session->getFlashBag()->get('error'));
    }

    public function testFailPaymentRequest_withoutASession_stillFailsThePaymentRequest(): void
    {
        // Reachable from the CLI (UpdatePaymentStateCommand) and from worker contexts, where
        // Request::getSession() would throw — a failed payment must not become a 500 because
        // there was nowhere to put a flash message.
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->createApplier($requestStack)->failPaymentRequest(
            $paymentRequest,
            $this->createMock(PaymentInterface::class),
            new \LogicException('boom'),
            PaymentCaptureFlow::Alias,
        );
    }

    public function testFailPaymentRequest_withNoRequestAtAll_stillFailsThePaymentRequest(): void
    {
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);

        $this->stateMachine->expects(self::once())->method('apply')
            ->with($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

        $this->createApplier(new RequestStack())->failPaymentRequest(
            $paymentRequest,
            $this->createMock(PaymentInterface::class),
            new \LogicException('boom'),
            PaymentCaptureFlow::Alias,
        );
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
