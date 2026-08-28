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
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\RefundCreatorInterface;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
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

    private RefundCreatorInterface&MockObject $refundCreator;

    private ILock&MockObject $lock;

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
        $this->refundCreator = $this->createMock(RefundCreatorInterface::class);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(true);

        $this->apiClientFactory->method('createForPaymentMethod')->willReturn($this->apiClient);

        $this->processor = new RefundPaymentProcessor(
            $this->requestStack,
            $this->logger,
            $this->translator,
            $this->refundPaymentRepository,
            $this->payplugRefundHistoryRepository,
            $this->apiClientFactory,
            $this->refundCreator,
            $this->lock,
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
    // process() — Wero gateway → refund is processed
    // -------------------------------------------------------------------------

    /**
     * Calls process() with a Wero payment; verifies refundPayment() is called,
     * confirming Wero is included in the supported gateway allow-list.
     */
    public function testProcess_weroGateway_callsApiRefund(): void
    {
        $payment = $this->buildPayment(WeroGatewayFactory::FACTORY_NAME, ['payment_id' => 'pay_wero']);

        $this->apiClient->expects(self::once())->method('refundPayment')->with('pay_wero');

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
    // process() — Hosted Fields (UHF) full refund → calls RefundCreatorInterface
    // -------------------------------------------------------------------------

    /**
     * Calls process() with a Hosted-Fields-configured payment. Verifies the UHF refund creator
     * is called with the payment's hosted_fields_payment_id and no amount (full refund), and the
     * legacy PayPlugApiClient is never touched.
     */
    public function testProcess_hostedFields_callsRefundCreatorWithoutAmount(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_123']);

        $this->refundCreator->expects(self::once())
            ->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_123', '000000042', null)
            ->willReturn(['status' => 200, 'body' => '{}']);
        $this->apiClient->expects(self::never())->method('refundPayment');

        $this->processor->process($payment);
    }

    /**
     * A full refund now records the refund's own operation id under $details['refunds'] (with a
     * null internal_id, since there's no Sylius RefundPayment/$refundId in this flow) — otherwise
     * HostedFieldsWebhookNotificationHandler could never resolve the payment for the async webhook
     * confirming this refund, since PaymentRepository::findOneByPayPlugPaymentId() matches on
     * ids present somewhere in Payment::details.
     */
    public function testProcess_hostedFields_recordsTheRefundOperationIdInDetails(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_123']);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details): bool {
                return [[
                    'internal_id' => null,
                    'id' => 'op_ref_full',
                    'amount' => 2400,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_123', '000000042', null)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_full']])]);

        $this->processor->process($payment);
    }

    /**
     * A full refund (process()) triggered after an earlier partial refund must record the
     * REMAINING amount actually refunded by omitting $amount to createRefund() — not
     * $payment->getAmount() (the original total, 2400 here) — per
     * UnifiedApiPaymentService::createRefund()'s own documented "omitting $amount refunds the
     * full remaining amount" behavior. Recording the original total instead would make
     * matchesPayment() reject this refund's own webhook confirmation (500 already refunded, 1900
     * really remaining) forever.
     */
    public function testProcess_hostedFields_afterAPriorPartialRefund_recordsTheRemainingAmountNotTheOriginalTotal(): void
    {
        $existingRefunds = [['internal_id' => 77, 'id' => 'op_ref_1', 'amount' => 500]];
        $payment = $this->buildHostedFieldsPayment([
            'hosted_fields_payment_id' => 'pay_hf_123',
            'refunds' => $existingRefunds,
        ]);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details) use ($existingRefunds): bool {
                return [...$existingRefunds, [
                    'internal_id' => null,
                    'id' => 'op_ref_full',
                    'amount' => 1900,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_123', '000000042', null)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_full']])]);

        $this->processor->process($payment);
    }

    /**
     * An earlier refund attempt flagged 'failed' => true by
     * HostedFieldsWebhookNotificationHandler::markMatchedRefundAsFailed() (its createRefund() call
     * was accepted synchronously, but the async confirmation later reported it never actually
     * completed) must not count against the remaining balance — a full refund triggered after it
     * still records the ORIGINAL total (2400), not 2400 minus the failed attempt's amount.
     */
    public function testProcess_hostedFields_afterAFailedPriorRefund_ignoresItInTheRemainingAmountCalculation(): void
    {
        $existingRefunds = [['internal_id' => 77, 'id' => 'op_ref_1', 'amount' => 500, 'failed' => true]];
        $payment = $this->buildHostedFieldsPayment([
            'hosted_fields_payment_id' => 'pay_hf_123',
            'refunds' => $existingRefunds,
        ]);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details) use ($existingRefunds): bool {
                return [...$existingRefunds, [
                    'internal_id' => null,
                    'id' => 'op_ref_full',
                    'amount' => 2400,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_123', '000000042', null)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_full']])]);

        $this->processor->process($payment);
    }

    /**
     * processHostedFields() must compute its remaining-balance sum from Payment::details read
     * AFTER acquiring RefundDetailsLockKey, not from a snapshot taken before it — otherwise a
     * concurrent HostedFieldsWebhookNotificationHandler::markMatchedRefundAsFailed() call (run
     * while this refund creation's own network call to createRefund() is in flight, both holding
     * the same lock key at different times) would have its 'failed' flag silently dropped when
     * this method's own stale pre-lock $details gets written back. Simulated here via
     * willReturnOnConsecutiveCalls: the first two getDetails() calls (prepare()'s own read, then
     * the pre-lock Assert::string(hosted_fields_payment_id) check) see the refund as NOT failed
     * yet; the third (taken once the lock is held, per processHostedFields()'s own re-read) sees
     * it flagged failed — exactly as if the webhook's write landed in between. Only that third
     * snapshot may ever reach setDetails().
     */
    public function testProcess_hostedFields_reReadsDetailsAfterAcquiringTheLock_soAConcurrentlyFlaggedFailedRefundIsNotLost(): void
    {
        $beforeLock = [
            'hosted_fields_payment_id' => 'pay_hf_123',
            'refunds' => [['internal_id' => 77, 'id' => 'op_ref_1', 'amount' => 500]],
        ];
        $afterLock = [
            'hosted_fields_payment_id' => 'pay_hf_123',
            'refunds' => [['internal_id' => 77, 'id' => 'op_ref_1', 'amount' => 500, 'failed' => true]],
        ];

        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn([PayPlugGatewayFactory::HOSTED_FIELDS => true]);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000000042');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturnOnConsecutiveCalls($beforeLock, $beforeLock, $afterLock);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(2400);
        $payment->method('getId')->willReturn(42);

        // The failed entry (500) must NOT be subtracted from the original total (2400): had the
        // pre-lock snapshot been used instead, this would incorrectly come out to 1900.
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details) use ($afterLock): bool {
                return [...$afterLock['refunds'], [
                    'internal_id' => null,
                    'id' => 'op_ref_full',
                    'amount' => 2400,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_123', '000000042', null)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_full']])]);

        $this->processor->process($payment);
    }

    /**
     * The lock key for a full refund and a partial refund on the SAME payment must be identical —
     * otherwise the two can run concurrently and both succeed, double-refunding money, exactly
     * the scenario the lock exists to prevent.
     */
    public function testProcess_andProcessWithAmount_useTheSameLockKeyForTheSamePayment(): void
    {
        $acquiredKeys = [];
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturnCallback(static function (string $key) use (&$acquiredKeys): bool {
            $acquiredKeys[] = $key;

            return true;
        });
        $this->processor = new RefundPaymentProcessor(
            $this->requestStack,
            $this->logger,
            $this->translator,
            $this->refundPaymentRepository,
            $this->payplugRefundHistoryRepository,
            $this->apiClientFactory,
            $this->refundCreator,
            $this->lock,
        );

        $this->refundCreator->method('createRefund')->willReturn(['status' => 200, 'body' => '{}']);
        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->willReturn($refundPayment);
        $this->payplugRefundHistoryRepository->method('findOneBy')->willReturn(null);

        $this->processor->process($this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_123']));
        $this->processor->processWithAmount($this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_123']), 500, 77);

        self::assertCount(2, $acquiredKeys);
        self::assertSame($acquiredKeys[0], $acquiredKeys[1]);
    }

    /**
     * A full refund has no RefundHistory/refundId to check-then-act on (mirrors the legacy
     * process()'s own lack of one), so the ILock guard is its only protection against a
     * concurrent second call for the same payment double-refunding.
     */
    public function testProcess_hostedFields_whenLockCannotBeAcquired_throwsUpdateHandlingExceptionWithoutCallingRefundCreator(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_123']);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(false);
        $this->processor = new RefundPaymentProcessor(
            $this->requestStack,
            $this->logger,
            $this->translator,
            $this->refundPaymentRepository,
            $this->payplugRefundHistoryRepository,
            $this->apiClientFactory,
            $this->refundCreator,
            $this->lock,
        );

        $this->refundCreator->expects(self::never())->method('createRefund');
        $this->logger->expects(self::once())->method('error');

        $this->processor->process($payment);
    }

    /**
     * The UHF refund creator throws an ApiException (a UPC exception, always a subtype of the
     * base \Exception). Verifies the processor catches it the same way as the legacy client's
     * exceptions, logs an error, and re-throws UpdateHandlingException.
     */
    public function testProcess_hostedFields_apiExceptionThrowsUpdateHandlingException(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_fail']);

        $this->refundCreator->method('createRefund')->willThrowException(new ApiException('API error'));
        $this->logger->expects(self::once())->method('error');

        $this->processor->process($payment);
    }

    // -------------------------------------------------------------------------
    // processWithAmount() — Hosted Fields (UHF) partial refund → RefundCreatorInterface +
    // RefundHistory bookkeeping
    // -------------------------------------------------------------------------

    /**
     * Calls processWithAmount() on a Hosted-Fields payment. Verifies the UHF refund creator is
     * called with the amount, setDetails() records the refund's own operation id (from the
     * response's operationIds[0]) under $details['refunds'], and a RefundHistory entry is
     * persisted — externalId stays null, mirroring the legacy flow's own convention that this
     * field is reserved for the async webhook-confirmed refund, not the synchronous BO one.
     */
    public function testProcessWithAmount_hostedFields_createsRefundHistoryEntryFromOperationIds(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial']);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details): bool {
                return [[
                    'internal_id' => 77,
                    'id' => 'op_ref_1',
                    'amount' => 500,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator
            ->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_partial', '000000042', 500)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_1']])]);

        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->with(['id' => 77])->willReturn($refundPayment);

        $this->payplugRefundHistoryRepository->expects(self::once())->method('add')->with(self::callback(
            static function (RefundHistory $refundHistory): bool {
                return null === $refundHistory->getExternalId() &&
                    500 === $refundHistory->getValue() &&
                    $refundHistory->isProcessed();
            },
        ));

        $this->processor->processWithAmount($payment, 500, 77);
    }

    public function testProcessWithAmount_hostedFields_apiExceptionThrowsUpdateHandlingException(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial_fail']);

        $this->refundCreator->method('createRefund')->willThrowException(new ApiException('fail'));
        $this->logger->expects(self::once())->method('error');

        $this->processor->processWithAmount($payment, 300, 42);
    }

    /**
     * The whole check-then-act sequence (RefundHistory lookup + createRefund() call) is also
     * guarded by ILock, keyed by $refundId: without it, two concurrent calls for the same
     * $refundId could both pass the RefundHistory check below before either persists one, and
     * both would go on to call createRefund() — a lock is what actually serializes the two
     * attempts, a plain check-then-act on its own cannot.
     */
    public function testProcessWithAmount_hostedFields_whenLockCannotBeAcquired_throwsUpdateHandlingExceptionWithoutCallingRefundCreator(): void
    {
        $this->expectException(UpdateHandlingException::class);

        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial']);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(false);
        $this->processor = new RefundPaymentProcessor(
            $this->requestStack,
            $this->logger,
            $this->translator,
            $this->refundPaymentRepository,
            $this->payplugRefundHistoryRepository,
            $this->apiClientFactory,
            $this->refundCreator,
            $this->lock,
        );

        $this->refundPaymentRepository->expects(self::never())->method('findOneBy');
        $this->refundCreator->expects(self::never())->method('createRefund');
        $this->logger->expects(self::once())->method('error');

        $this->processor->processWithAmount($payment, 500, 77);
    }

    /**
     * Unlike the legacy flow (which forwards Sylius's own $refundId to the API as a de-facto
     * idempotency key), UPC's createRefund() has no idempotency-key parameter at all. A retried
     * delivery of the same RefundPaymentGenerated message (e.g. after a transient failure once
     * the RefundHistory for this $refundId was already persisted) must not call createRefund()
     * again — this is the local guard closing that window.
     */
    public function testProcessWithAmount_hostedFields_alreadyProcessed_skipsDuplicateRefundCall(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial']);
        $payment->expects(self::never())->method('setDetails');

        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->with(['id' => 77])->willReturn($refundPayment);

        $existingRefundHistory = $this->createMock(RefundHistory::class);
        $this->payplugRefundHistoryRepository
            ->method('findOneBy')
            ->with(['refundPayment' => $refundPayment])
            ->willReturn($existingRefundHistory);

        $this->refundCreator->expects(self::never())->method('createRefund');
        $this->payplugRefundHistoryRepository->expects(self::never())->method('add');

        $this->processor->processWithAmount($payment, 500, 77);
    }

    /**
     * createRefund() returns a 2xx response whose body has no operationIds (malformed/unexpected
     * shape). The refund still succeeded (money moved) and is still recorded, but with no
     * tracking id — this must not pass silently, so an error is logged (actionable: without an
     * operation id, HostedFieldsWebhookNotificationHandler can never match the eventual webhook
     * confirmation back to this refund).
     */
    public function testProcessWithAmount_hostedFields_onMissingOperationIds_logsAnError(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial']);

        $this->refundCreator->method('createRefund')->willReturn(['status' => 200, 'body' => '{}']);

        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->willReturn($refundPayment);
        $this->payplugRefundHistoryRepository->method('findOneBy')->willReturn(null);

        $this->logger->expects(self::once())->method('error');
        $this->payplugRefundHistoryRepository->expects(self::once())->method('add');

        $this->processor->processWithAmount($payment, 500, 77);
    }

    /**
     * Two sequential partial refunds against the same Hosted-Fields payment must accumulate in
     * $details['refunds'] rather than the second call overwriting the first — mirrors the
     * legacy gateway's own Behat coverage for this exact scenario ("Two Partial refund of one
     * product"), which UHF otherwise has no equivalent for at any test level.
     */
    public function testProcessWithAmount_hostedFields_secondPartialRefund_appendsToExistingRefunds(): void
    {
        $existingRefunds = [['internal_id' => 77, 'id' => 'op_ref_1', 'amount' => 500]];
        $payment = $this->buildHostedFieldsPayment([
            'hosted_fields_payment_id' => 'pay_hf_partial',
            'refunds' => $existingRefunds,
        ]);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details) use ($existingRefunds): bool {
                return [...$existingRefunds, [
                    'internal_id' => 78,
                    'id' => 'op_ref_2',
                    'amount' => 300,
                ]] === $details['refunds'];
            },
        ));

        $this->refundCreator
            ->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_partial', '000000042', 300)
            ->willReturn(['status' => 200, 'body' => json_encode(['operationIds' => ['op_ref_2']])]);

        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->with(['id' => 78])->willReturn($refundPayment);
        $this->payplugRefundHistoryRepository->method('findOneBy')->willReturn(null);

        $this->processor->processWithAmount($payment, 300, 78);
    }

    /**
     * prepare() must skip the legacy PayPlugApiClientFactory entirely for a Hosted-Fields
     * payment — building it would mint an OAuth2 token that's never used.
     */
    public function testProcessWithAmount_hostedFields_neverCreatesTheLegacyApiClient(): void
    {
        $payment = $this->buildHostedFieldsPayment(['hosted_fields_payment_id' => 'pay_hf_partial']);

        $this->refundCreator->method('createRefund')->willReturn(['status' => 200, 'body' => '{}']);
        $refundPayment = $this->createMock(RefundPayment::class);
        $this->refundPaymentRepository->method('findOneBy')->willReturn($refundPayment);
        $this->payplugRefundHistoryRepository->method('findOneBy')->willReturn(null);

        $this->apiClientFactory->expects(self::never())->method('createForPaymentMethod');

        $this->processor->processWithAmount($payment, 500, 77);
    }

    /**
     * When the payment has no order (edge case), orderId falls back to the payment's own id —
     * same convention CaptureHostedPaymentRequestHandler already uses at creation time.
     */
    public function testProcess_hostedFields_withNoOrder_fallsBackToThePaymentIdAsOrderId(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn([PayPlugGatewayFactory::HOSTED_FIELDS => true]);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturn(['hosted_fields_payment_id' => 'pay_hf_no_order']);
        $payment->method('getOrder')->willReturn(null);
        $payment->method('getId')->willReturn(99);
        $payment->method('getAmount')->willReturn(2400);

        $this->refundCreator->expects(self::once())
            ->method('createRefund')
            ->with(self::isInstanceOf(PaymentMethodInterface::class), 'pay_hf_no_order', '99', null)
            ->willReturn(['status' => 200, 'body' => '{}']);

        $this->processor->process($payment);
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

    private function buildHostedFieldsPayment(array $details): PaymentInterface&MockObject
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->method('getConfig')->willReturn([PayPlugGatewayFactory::HOSTED_FIELDS => true]);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000000042');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(2400);
        $payment->method('getId')->willReturn(42);

        return $payment;
    }
}
