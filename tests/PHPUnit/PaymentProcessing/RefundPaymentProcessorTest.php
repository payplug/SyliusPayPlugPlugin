<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\PaymentProcessing;

use Exception;
use Payplug\Resource\Refund;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RefundPaymentProcessorTest extends TestCase
{
    private RequestStack&MockObject $requestStack;

    private LoggerInterface&MockObject $logger;

    private TranslatorInterface&MockObject $translator;

    private RepositoryInterface&MockObject $refundPaymentRepository;

    private RefundHistoryRepositoryInterface&MockObject $payplugRefundHistoryRepository;

    private PayPlugApiClientFactoryInterface&MockObject $apiClientFactory;

    private PayPlugApiClientInterface&MockObject $apiClient;

    private RefundPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->refundPaymentRepository = $this->createMock(RepositoryInterface::class);
        $this->payplugRefundHistoryRepository = $this->createMock(RefundHistoryRepositoryInterface::class);
        $this->apiClientFactory = $this->createMock(PayPlugApiClientFactoryInterface::class);
        $this->apiClient = $this->createMock(PayPlugApiClientInterface::class);

        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($this->apiClient);

        $this->processor = new RefundPaymentProcessor(
            $this->requestStack,
            $this->logger,
            $this->translator,
            $this->refundPaymentRepository,
            $this->payplugRefundHistoryRepository,
            $this->apiClientFactory,
        );
    }

    // -------------------------------------------------------------------------
    // process() — full refund success
    // -------------------------------------------------------------------------

    /**
     * Calls process() with a valid payment containing a payment_id and the PayPlug factory.
     * Verifies the API client's refundPayment() is called once with the correct payment ID.
     */
    public function testProcess_fullRefundSuccess_callsApiRefund(): void
    {
        $payment = $this->buildPayment(PayPlugGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_abc']);

        $this->apiClient->expects(self::once())->method('refundPayment')->with('pay_abc');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // process() — Scalapay gateway → refund is processed
    // -------------------------------------------------------------------------

    /**
     * Calls process() with a Scalapay payment; verifies the API client factory is invoked
     * and refundPayment() is called, confirming Scalapay is included in the supported gateway list.
     */
    public function testProcess_scalapayGateway_callsApiRefund(): void
    {
        $payment = $this->buildPayment(ScalapayGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_scalapay']);

        $this->apiClientFactory->expects(self::once())->method('createForPaymentMethod');
        $this->apiClient->expects(self::once())->method('refundPayment')->with('pay_scalapay');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // process() — API exception → UpdateHandlingException
    // -------------------------------------------------------------------------

    /**
     * The API client throws a generic Exception during refundPayment().
     * Verifies the processor catches it, logs an error, and re-throws UpdateHandlingException.
     */
    public function testProcess_apiThrowsException_throwsUpdateHandlingException(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildPayment(PayPlugGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_fail']);

        $this->apiClient->method('refundPayment')->willThrowException(new Exception('API error'));
        $this->logger->expects(self::once())->method('error');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // prepare() — gateway config is null → prepare() returns early, no API client created
    // -------------------------------------------------------------------------

    /**
     * The payment method returns null for getGatewayConfig(), so prepare() exits early.
     * Verifies the API factory is never called and a Throwable is thrown (uninitialized client).
     */
    public function testProcess_nullGatewayConfig_skipsRefundWithoutApiCall(): void
    {
        // Build a payment where getGatewayConfig() returns null
        $paymentMethod = $this->createMock(\Sylius\Component\Core\Model\PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn(null);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturn(['payment_id' => 'pay_xyz']);

        // Null gateway config → prepare() returns early without calling API factory
        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        // process() after prepare() returns early → Assert::string('pay_xyz') passes
        // → $this->payPlugApiClient uninitialized → TypeError
        // This reflects the production code design limitation.
        $this->expectException(\Throwable::class);

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // onRefundCompleteTransitionEvent() — non-PaymentInterface subject → returns early
    // -------------------------------------------------------------------------

    /**
     * Fires onRefundCompleteTransitionEvent() with a plain stdClass as the workflow subject.
     * Verifies the handler returns early without ever calling the API.
     */
    public function testOnRefundCompleteTransitionEvent_withNonPaymentSubject_doesNothing(): void
    {
        // CompletedEvent is final — build a real one with a non-Payment subject.
        $marking = new \Symfony\Component\Workflow\Marking();
        $event = new \Symfony\Component\Workflow\Event\CompletedEvent(new \stdClass(), $marking);

        $this->apiClient->expects(self::never())->method('refundPayment');

        $this->processor->onRefundCompleteTransitionEvent($event);
    }

    // -------------------------------------------------------------------------
    // processWithAmount() — partial refund success, creates RefundHistory
    // -------------------------------------------------------------------------

    /**
     * Calls processWithAmount() with amount=500 and refundPaymentId=77; the API returns a Refund object.
     * Verifies setDetails() is called on the payment and the RefundHistory entry is persisted via add().
     */
    public function testProcessWithAmount_success_createsRefundHistoryEntry(): void
    {
        $payment = $this->buildPayment(PayPlugGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_partial']);
        $payment->expects(self::once())->method('setDetails');

        $refundApiObject = $this->createMock(Refund::class);
        $refundApiObject->id = 'ref_ext_001';
        $refundApiObject->amount = 500;
        $refundApiObject->metadata = [];

        $this->apiClient
            ->method('refundPaymentWithAmount')
            ->with('pay_partial', 500, 77)
            ->willReturn($refundApiObject)
        ;

        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->with(['id' => 77])->willReturn($refundPayment);

        $this->payplugRefundHistoryRepository->expects(self::once())->method('add');

        $this->processor->processWithAmount($payment, 500, 77);
    }

    // -------------------------------------------------------------------------
    // processWithAmount() — API exception → UpdateHandlingException
    // -------------------------------------------------------------------------

    /**
     * The API client throws during refundPaymentWithAmount() for a partial refund.
     * Verifies the processor logs the error and re-throws UpdateHandlingException.
     */
    public function testProcessWithAmount_apiThrowsException_throwsUpdateHandlingException(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildPayment(PayPlugGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_partial_fail']);

        $this->apiClient->method('refundPaymentWithAmount')->willThrowException(new Exception('fail'));
        $this->logger->expects(self::once())->method('error');

        $this->processor->processWithAmount($payment, 300, 42);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPayment(string $factoryName, array $details): PaymentInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factoryName);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturn($details);

        return $payment;
    }
}
