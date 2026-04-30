<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Action;

use Payplug\Resource\Payment as PayplugPayment;
use PayPlug\SyliusPayPlugPlugin\Action\StatusAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentHandlerInterface;
use PayPlug\SyliusPayPlugPlugin\StateMachine\Transition\OrderPaymentTransitions;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\GetStatusInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class StatusActionTest extends TestCase
{
    private StateMachineInterface&MockObject $stateMachine;

    private RefundPaymentHandlerInterface&MockObject $refundPaymentHandler;

    private PaymentNotificationHandler&MockObject $paymentNotificationHandler;

    private RequestStack&MockObject $requestStack;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private GatewayInterface&MockObject $gateway;

    private StatusAction $action;

    protected function setUp(): void
    {
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->refundPaymentHandler = $this->createMock(RefundPaymentHandlerInterface::class);
        $this->paymentNotificationHandler = $this->createMock(PaymentNotificationHandler::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);
        $this->gateway = $this->createMock(GatewayInterface::class);

        $this->action = new StatusAction(
            $this->stateMachine,
            $this->refundPaymentHandler,
            $this->paymentNotificationHandler,
            $this->requestStack,
        );
        $this->action->setApi($this->apiClient);
        $this->action->setGateway($this->gateway);
    }

    // -------------------------------------------------------------------------
    // supports()
    // -------------------------------------------------------------------------

    /**
     * Passes a GetStatusInterface request whose model is a PaymentInterface.
     * Verifies supports() returns true (the action handles this combination).
     */
    public function testSupports_withGetStatusAndPaymentModel_returnsTrue(): void
    {
        $request = $this->createMock(GetStatusInterface::class);
        $request->method('getModel')->willReturn($this->createMock(PaymentInterface::class));

        self::assertTrue($this->action->supports($request));
    }

    /**
     * Passes a GetStatusInterface request whose model is a plain stdClass (not a payment).
     * Verifies supports() returns false.
     */
    public function testSupports_withNonPaymentModel_returnsFalse(): void
    {
        $request = $this->createMock(GetStatusInterface::class);
        $request->method('getModel')->willReturn(new \stdClass());

        self::assertFalse($this->action->supports($request));
    }

    // -------------------------------------------------------------------------
    // execute() — missing status or payment_id → markNew
    // -------------------------------------------------------------------------

    /**
     * Payment details are empty (no status, no payment_id) — payment was never initiated.
     * Verifies execute() calls markNew() on the request.
     */
    public function testExecute_missingStatusAndPaymentId_marksNew(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markNew');

        $this->action->execute($request);
    }

    /**
     * Payment details have a status but no payment_id (redirect happened, ID not yet stored).
     * Verifies execute() calls markNew() (cannot poll the API without an ID).
     */
    public function testExecute_missingPaymentId_marksNew(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markNew');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — STATUS_CANCELED → markCanceled
    // -------------------------------------------------------------------------

    /**
     * Payment details contain STATUS_CANCELED and a valid payment_id.
     * Verifies execute() calls markCanceled() on the request.
     */
    public function testExecute_canceledStatus_marksCanceled(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::STATUS_CANCELED,
            'payment_id' => 'pay_001',
        ]);
        $payment->method('setDetails');

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markCanceled');

        $this->gateway->method('execute'); // GetHttpRequest

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — STATUS_CAPTURED → markCaptured
    // -------------------------------------------------------------------------

    /**
     * Payment details contain STATUS_CAPTURED and a valid payment_id.
     * Verifies execute() calls markCaptured() on the request.
     */
    public function testExecute_capturedStatus_marksCaptured(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::STATUS_CAPTURED,
            'payment_id' => 'pay_002',
        ]);
        $payment->method('setDetails');

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markCaptured');

        $this->gateway->method('execute');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — FAILED → markFailed
    // -------------------------------------------------------------------------

    /**
     * Payment details contain FAILED status and a valid payment_id.
     * Verifies execute() calls markFailed() on the request.
     */
    public function testExecute_failedStatus_marksFailed(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::FAILED,
            'payment_id' => 'pay_003',
        ]);
        $payment->method('setDetails');

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markFailed');

        $this->gateway->method('execute');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — STATUS_AUTHORIZED → markAuthorized
    // -------------------------------------------------------------------------

    /**
     * Payment details contain STATUS_AUTHORIZED (deferred capture pending).
     * Verifies execute() calls markAuthorized() on the request.
     */
    public function testExecute_authorizedStatus_marksAuthorized(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::STATUS_AUTHORIZED,
            'payment_id' => 'pay_004',
        ]);
        $payment->method('setDetails');

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markAuthorized');

        $this->gateway->method('execute');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — STATUS_CANCELED_BY_ONEY → markCanceled + state machine transition
    // -------------------------------------------------------------------------

    /**
     * Payment details contain STATUS_CANCELED_BY_ONEY (Oney refused the financing request).
     * Verifies markCanceled() is called and the state machine applies the oney_request_payment transition.
     */
    public function testExecute_canceledByOney_marksCanceledAndAppliesOneyTransition(): void
    {
        $order = $this->createMock(OrderInterface::class);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::STATUS_CANCELED_BY_ONEY,
            'payment_id' => 'pay_005',
        ]);
        $payment->method('setDetails');
        $payment->method('getOrder')->willReturn($order);

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markCanceled');

        $this->gateway->method('execute');

        $this->stateMachine->method('can')->willReturn(true);
        $this->stateMachine->expects(self::once())
            ->method('apply')
            ->with($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_ONEY_REQUEST_PAYMENT)
        ;

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — STATUS_CREATED + payum_token in query → polls API
    // -------------------------------------------------------------------------

    /**
     * Status is STATUS_CREATED and the HTTP request carries a payum_token (customer returning from PayPlug).
     * Verifies the API client's retrieve() is called once and the notification handler processes the result.
     */
    public function testExecute_createdStatusWithPayumToken_retrievesFromApi(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => PayPlugApiClientInterface::STATUS_CREATED,
            'payment_id' => 'pay_006',
        ]);
        $payment->method('setDetails');
        $payment->method('getOrder')->willReturn($this->createMock(OrderInterface::class));

        $request = $this->buildStatusRequest($payment);

        // Simulate GetHttpRequest populating query['payum_token']
        $this->gateway->method('execute')->willReturnCallback(function ($req) {
            if ($req instanceof \Payum\Core\Request\GetHttpRequest) {
                $req->query['payum_token'] = 'tok_xyz';
            }
        });

        $apiPayment = $this->createMock(PayplugPayment::class);
        $this->apiClient->expects(self::once())->method('retrieve')->with('pay_006')->willReturn($apiPayment);
        $this->paymentNotificationHandler->expects(self::once())->method('treat');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // execute() — unknown status → markUnknown
    // -------------------------------------------------------------------------

    /**
     * Payment details contain an unrecognised status string not covered by any case in markRequestAs().
     * Verifies execute() calls markUnknown() on the request.
     */
    public function testExecute_unknownStatus_marksUnknown(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            'status' => 'some_unknown_status',
            'payment_id' => 'pay_007',
        ]);
        $payment->method('setDetails');

        $request = $this->buildStatusRequest($payment);
        $request->expects(self::once())->method('markUnknown');

        $this->gateway->method('execute');

        $this->action->execute($request);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Use GetHumanStatus (concrete class) so all interface methods + getFirstModel() are available.
     */
    private function buildStatusRequest(PaymentInterface $payment): GetHumanStatus&MockObject
    {
        $request = $this->getMockBuilder(GetHumanStatus::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $request->method('getModel')->willReturn($payment);
        $request->method('getFirstModel')->willReturn($payment);

        return $request;
    }
}
