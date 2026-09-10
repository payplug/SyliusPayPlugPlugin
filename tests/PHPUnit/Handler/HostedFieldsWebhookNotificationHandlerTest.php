<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Handler;

use Doctrine\Persistence\ManagerRegistry;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Upc\PayplugCardPersister;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class HostedFieldsWebhookNotificationHandlerTest extends TestCase
{
    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

    private ILock&MockObject $lock;

    private LoggerInterface&MockObject $logger;

    private FactoryInterface&MockObject $payplugCardFactory;

    private RepositoryInterface&MockObject $payplugCardRepository;

    private ManagerRegistry&MockObject $managerRegistry;

    private HostedFieldsWebhookNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->payplugCardFactory = $this->createMock(FactoryInterface::class);
        $this->payplugCardRepository = $this->createMock(RepositoryInterface::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);

        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->lock,
            $this->logger,
            new PayplugCardPersister($this->payplugCardFactory, $this->payplugCardRepository, $this->managerRegistry),
        );
    }

    /**
     * @param mixed[] $details
     */
    private function payment(
        int $id = 42,
        int $amount = 1000,
        ?string $orderNumber = null,
        array $details = [],
        ?PaymentMethodInterface $method = null,
        ?CustomerInterface $customer = null,
    ): PaymentInterface&MockObject {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn($id);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getDetails')->willReturn($details);
        $payment->method('getMethod')->willReturn($method);

        if (null !== $orderNumber || null !== $customer) {
            $order = $this->createMock(OrderInterface::class);
            $order->method('getNumber')->willReturn($orderNumber);
            $order->method('getCustomer')->willReturn($customer);
            $payment->method('getOrder')->willReturn($order);
        } else {
            $payment->method('getOrder')->willReturn(null);
        }

        return $payment;
    }

    public function testTreat_onValidNotification_savesTreatsAndAppliesTheOutcomeAgainstTheResolvedPayment(): void
    {
        // orderId here ("42") matches the payment id fallback used when the payment has no order
        // yet — the same fallback CaptureHostedPaymentRequestHandler uses when sending orderId to
        // PayPlug at creation time (order number if present, else the payment id).
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        $this->configurationRepository->method('get')->with('payplug_webhook_authorization_header')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(false);

        $this->lock->expects(self::once())->method('acquire')->with('payplug_upc_treat_op_123', 30)->willReturn(true);
        $this->lock->expects(self::once())->method('release')->with('payplug_upc_treat_op_123');
        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_123');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * Guards the race StatusHostedPaymentRequestHandler's GET polling fallback can create: if a
     * genuine webhook delivery for the same operation is already inside treat() (lock held), a
     * concurrent caller must back off rather than double-apply.
     */
    public function testTreat_whenLockCannotBeAcquired_doesNothingAndReturnsSilently(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        // A fresh mock rather than reconfiguring $this->lock: setUp()'s unconditional
        // ->method('acquire')->willReturn(true) stub would otherwise still win over this one.
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(false);
        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->lock,
            $this->logger,
            new PayplugCardPersister($this->payplugCardFactory, $this->payplugCardRepository, $this->managerRegistry),
        );

        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->lock->expects(self::never())->method('release');

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onPendingThreeDsExecCode_doesNothingAndReturnsSilently(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0001', 'orderId' => '42', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->lock->expects(self::never())->method('acquire');
        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * Regression for the live incident on 2026-08-21 (order 000000074): the notifier fired a
     * webhook mid-3DS-challenge (execCode 0001) before the real, final one (execCode 0000). The
     * premature call must not consume isTreated()'s dedupe slot, or the later, correct
     * notification has nothing left to do — the payment ends up permanently stuck instead of paid.
     */
    public function testTreat_onPendingExecCodeFollowedByFinalExecCode_appliesOnlyTheFinalOutcome(): void
    {
        $pendingBody = \json_encode(['id' => 'op_123', 'execCode' => '0001', 'orderId' => '42', 'amount' => 1000]);
        $finalBody = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(false);

        $this->handler->treat($this->payment(42, 1000), $pendingBody, ['Authorization' => 'Bearer shared-secret']);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_123');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->treat($this->payment(42, 1000), $finalBody, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onRealPlatformShapedNotification_stillParsesTheFourFieldsWebhookNotificationHelperNeeds(): void
    {
        // Captured from Datadog (staging notifier webhook attempts): the platform sends a much
        // richer, nested payload than the {id, execCode, orderId, amount} shape our own tests
        // otherwise use. WebhookNotificationHelper::parse() only reads those four top-level
        // fields and ignores everything else, so this locks in that the extra nesting (customer,
        // authentication, paymentMethod, account...) and sibling fields (paymentId, stan,
        // descriptor...) never break parsing.
        $body = \json_encode([
            'operationType' => 'PAYMENT',
            'customer' => ['id' => '130', 'email' => 'test-client@example.com'],
            'authentication' => ['status' => 'Y', 'globalStatus' => 'OK', 'mode' => 'FRICTIONLESS', 'preference' => 'NO_PREF', 'version' => '2', 'enrolledCard' => 'Y'],
            'paymentMethod' => ['card' => ['bank' => 'EXAMPLE BANK', 'country' => 'GB', 'usage' => 'debit', 'code6x4' => '446421XXXXXX0000', 'type' => 'VISA', 'network' => 'VISA'], 'details' => ['validityDate' => '2030-12', 'selectedBrand' => 'VISA']],
            'account' => ['id' => 'PLUGINS_UHF_QA'],
            'additionalData' => 'Playful Paradise Cap',
            'currency' => 'EUR',
            'amount' => 7400,
            'descriptor' => 'PPG',
            'authorizationCode' => '452743',
            'bankResponse' => '00',
            'schemeTransactionId' => 'G8N6XKPB07CO5JT',
            'execCode' => '0000',
            'message' => 'Successful operation',
            'orderId' => '000000065',
            'stan' => '333446',
            'id' => 'e4d04233-a15d-4815-af91-698c3eb61c36',
            'paymentId' => 'b1dde7ce-d069-43dd-b49d-9f2f1cd9d671',
        ]);

        $this->configurationRepository->method('get')->with('payplug_webhook_authorization_header')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('e4d04233-a15d-4815-af91-698c3eb61c36')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('e4d04233-a15d-4815-af91-698c3eb61c36');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->treat($this->payment(42, 7400, '000000065'), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_whenAlreadyTreated_isIdempotentAndDoesNotReapply(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_123')->willReturn(true);

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->lock->expects(self::once())->method('release')->with('payplug_upc_treat_op_123');

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onInvalidSignature_throwsInvalidNotificationExceptionWithoutApplyingTheOutcome(): void
    {
        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->expectException(InvalidNotificationException::class);

        $this->handler->treat($this->payment(), '{}', ['Authorization' => 'Bearer wrong-secret']);
    }

    public function testTreat_onOrderIdMismatch_logsAndSkipsWithoutApplyingTheOutcome(): void
    {
        // Now that no Authorization header is required (see WebhookNotificationHelper), this
        // orderId/amount cross-check is the only remaining protection against a notification
        // being applied to the wrong payment on the static, per-account IPN receiver.
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => 'some-other-order', 'amount' => 1000]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->logger->expects(self::once())->method('error');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onAmountMismatch_logsAndSkipsWithoutApplyingTheOutcome(): void
    {
        $body = \json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '42', 'amount' => 999]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->logger->expects(self::once())->method('error');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->treat($this->payment(42, 1000), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * A 3DS-challenge capture never gets an alias back synchronously — this webhook, fired once
     * the challenge is validated, is the only place a 3DS payment's card ever gets saved. The
     * alias/card metadata is already in the webhook body itself: same paymentMethod.{id, card,
     * details} shape as the operation resource CaptureHostedPaymentRequestHandler fetches
     * separately for a frictionless payment.
     */
    public function testTreat_onPaidOutcomeWithSaveCardRequested_persistsANewCard(): void
    {
        $body = \json_encode([
            'id' => 'op_123',
            'execCode' => '0000',
            'orderId' => '42',
            'amount' => 1000,
            'paymentMethod' => [
                'id' => 'card_new_1',
                'card' => ['network' => 'VISA', 'code6x4' => '424242XXXXXX4242'],
                'details' => ['selectedBrand' => 'VISA', 'validityDate' => '2030-12'],
            ],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $method = $this->createMock(PaymentMethodInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $payment = $this->payment(42, 1000, details: ['hosted_fields_save_card' => true], method: $method, customer: $customer);

        $card = new Card();
        $this->payplugCardFactory->method('createNew')->willReturn($card);
        $this->payplugCardRepository->expects(self::once())->method('add')->with($card);

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);

        self::assertSame('card_new_1', $card->getExternalId());
        self::assertSame('VISA', $card->getBrand());
        self::assertSame('4242', $card->getLast4());
        self::assertSame(12, $card->getExpirationMonth());
        self::assertSame(2030, $card->getExpirationYear());
    }

    public function testTreat_onPaidOutcomeWithoutSaveCardRequested_doesNotPersistACard(): void
    {
        $body = \json_encode([
            'id' => 'op_123',
            'execCode' => '0000',
            'orderId' => '42',
            'amount' => 1000,
            'paymentMethod' => ['id' => 'card_new_1', 'card' => ['network' => 'VISA', 'code6x4' => '424242XXXXXX4242']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $payment = $this->payment(42, 1000, details: ['hosted_fields_save_card' => false]);

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onPaidOutcomeWithSaveCardRequestedButNoAliasInPayload_logsAndDoesNotPersistACard(): void
    {
        $body = \json_encode([
            'id' => 'op_123',
            'execCode' => '0000',
            'orderId' => '42',
            'amount' => 1000,
            // No paymentMethod.id — e.g. this operation never involved an alias at all.
            'paymentMethod' => ['card' => ['network' => 'VISA', 'code6x4' => '424242XXXXXX4242']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $method = $this->createMock(PaymentMethodInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $payment = $this->payment(42, 1000, details: ['hosted_fields_save_card' => true], method: $method, customer: $customer);

        $this->logger->expects(self::once())->method('error')
            ->with(self::stringContains('no alias id'), self::anything());
        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onNonPaidOutcome_doesNotAttemptToPersistACard(): void
    {
        // execCode "9999" maps to PaymentOutcome::FAILED (not PAID, not the 0001 pending case
        // already covered elsewhere) — the card-save branch must not even be attempted.
        $body = \json_encode([
            'id' => 'op_123',
            'execCode' => '9999',
            'orderId' => '42',
            'amount' => 1000,
            'paymentMethod' => ['id' => 'card_new_1', 'card' => ['network' => 'VISA', 'code6x4' => '424242XXXXXX4242']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $payment = $this->payment(42, 1000, details: ['hosted_fields_save_card' => true]);

        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    public function testTreat_onPaidOutcomeWithSaveCardRequestedAndCardAlreadySaved_doesNotPersistADuplicate(): void
    {
        $body = \json_encode([
            'id' => 'op_123',
            'execCode' => '0000',
            'orderId' => '42',
            'amount' => 1000,
            'paymentMethod' => ['id' => 'card_existing_1', 'card' => ['network' => 'VISA', 'code6x4' => '424242XXXXXX4242']],
        ]);

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->willReturn(false);

        $method = $this->createMock(PaymentMethodInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $payment = $this->payment(42, 1000, details: ['hosted_fields_save_card' => true], method: $method, customer: $customer);

        $this->payplugCardRepository->method('findOneBy')->with(['externalId' => 'card_existing_1', 'isLive' => false])->willReturn(new Card());
        $this->payplugCardRepository->expects(self::never())->method('add');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * When the notification's operation id matches one recorded under $details['refunds']
     * (RefundPaymentProcessor stores it there for both full and partial UHF refunds), this is a
     * refund confirmation, not the payment's own outcome — ExecCodeMapper's "0000" => PAID mapping
     * would otherwise misreport a successful refund as the payment being paid. The amount check
     * must use the refund's own recorded amount (500), not the payment's full amount (1000).
     */
    public function testTreat_onNotificationMatchingAKnownRefundId_appliesRefundedInsteadOfThePaymentOutcome(): void
    {
        $body = \json_encode(['id' => 'op_refund_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 500]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => 'op_refund_1', 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_refund_1')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_refund_1');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::REFUNDED);

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * A refund confirmation is matched against its OWN recorded amount (500), not the payment's
     * full amount (1000) — the pre-fix behavior (comparing against the payment's full amount)
     * would reject every partial-refund confirmation, which is exactly the bug this feature fixes.
     */
    public function testTreat_onRefundNotificationAmountMismatch_logsAndSkipsWithoutApplyingTheOutcome(): void
    {
        $body = \json_encode(['id' => 'op_refund_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 400]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => 'op_refund_1', 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->logger->expects(self::once())->method('error');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->orderStateMutator->expects(self::never())->method('apply');

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * A full UHF refund records its operation id with internal_id: null (RefundPaymentProcessor::
     * processHostedFields() has no Sylius $refundId to attach) — still resolvable and REFUNDED.
     */
    public function testTreat_onFullRefundNotification_appliesRefundedUsingTheFullRefundAmount(): void
    {
        $body = \json_encode(['id' => 'op_refund_full', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $details = ['refunds' => [['internal_id' => null, 'id' => 'op_refund_full', 'amount' => 1000]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_refund_full')->willReturn(false);

        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::REFUNDED);

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * RefundPaymentProcessor's createRefund() call can return a 2xx response with no operationIds
     * (logged as an error there) — the refund entry it records then has id: null, so this
     * confirmation's own operationId ("op_refund_unresolved") can never match it by id. Falling
     * back to the unresolved entry's own recorded amount (500) is what still lets this be
     * classified as REFUNDED instead of being dropped or misapplied as a plain payment
     * confirmation.
     */
    public function testTreat_onNotificationForARefundWithNoCapturedOperationId_fallsBackToTheUnresolvedRefundEntry(): void
    {
        $body = \json_encode(['id' => 'op_refund_unresolved', 'execCode' => '0000', 'orderId' => '42', 'amount' => 500]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => null, 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_refund_unresolved')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_refund_unresolved');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::REFUNDED);

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * The refund's own execCode indicates failure (anything other than "0000") — the outcome
     * must never be forced to REFUNDED (money never moved), nor passed through as-is to the
     * Payment's own state machine: PaymentOutcome::FAILED maps to TRANSITION_FAIL (see
     * SyliusOrderStateMutator), which means "this PAYMENT failed," not "this refund attempt
     * failed" — the underlying payment already succeeded, only the refund didn't. Only logging +
     * idempotency tracking happen; orderStateMutator must never be called. The matched refund
     * entry is also flagged 'failed' => true on the Payment itself — see
     * RefundPaymentProcessor::sumRecordedRefunds(), which relies on this flag to exclude money
     * that was accepted synchronously but never actually moved from a later full refund's
     * remaining-balance calculation.
     */
    public function testTreat_onRefundNotificationWithFailureExecCode_neverTouchesThePaymentStateButStillMarksTreated(): void
    {
        $body = \json_encode(['id' => 'op_refund_1', 'execCode' => '9999', 'orderId' => '42', 'amount' => 500]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => 'op_refund_1', 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_refund_1')->willReturn(false);

        $payment = $this->payment(42, 1000, null, $details);
        $payment->expects(self::once())->method('setDetails')->with(self::callback(
            static function (array $details): bool {
                return [[
                    'internal_id' => 77,
                    'id' => 'op_refund_1',
                    'amount' => 500,
                    'failed' => true,
                ]] === $details['refunds'];
            },
        ));

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_refund_1');
        $this->orderStateMutator->expects(self::never())->method('apply');
        $this->logger->expects(self::once())->method('error');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * The 'failed' flag write goes through RefundDetailsLockKey — the same lock key
     * RefundPaymentProcessor::processHostedFields()/processHostedFieldsWithAmount() acquire
     * around their own (network-call-spanning) read-modify-write of this same
     * $details['refunds'] array — not the per-operation 'payplug_upc_treat_' lock applyLocked()
     * uses afterwards. Both locks are acquired/released here: the refund-details one first
     * (guarding the setDetails() write below), the treat one second (guarding
     * isTreated()/markTreated()/save()).
     */
    public function testTreat_onRefundNotificationWithFailureExecCode_acquiresTheSharedRefundDetailsLockKeyBeforeWritingTheFailedFlag(): void
    {
        $body = \json_encode(['id' => 'op_refund_1', 'execCode' => '9999', 'orderId' => '42', 'amount' => 500]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => 'op_refund_1', 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_refund_1')->willReturn(false);

        $acquiredKeys = [];
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturnCallback(static function (string $key, int $ttl) use (&$acquiredKeys): bool {
            $acquiredKeys[] = [$key, $ttl];

            return true;
        });
        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->lock,
            $this->logger,
            new PayplugCardPersister($this->payplugCardFactory, $this->payplugCardRepository, $this->managerRegistry),
        );

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);

        self::assertSame(
            [['payplug_upc_refund_details_42', 30], ['payplug_upc_treat_op_refund_1', 30]],
            $acquiredKeys,
        );
    }

    /**
     * A refund creation (RefundPaymentProcessor::processHostedFields()/
     * processHostedFieldsWithAmount()) is in progress for this payment right now, holding
     * RefundDetailsLockKey. The notification must NOT be marked treated in that case — returning
     * without ever calling applyLocked() leaves isTreated()/markTreated() untouched, so a later
     * redelivery of the same notification gets a fresh chance to record the 'failed' flag once
     * that refund creation has released the lock — instead of the flag being silently lost forever
     * because this delivery was marked treated without ever recording it.
     */
    public function testTreat_onRefundNotificationWithFailureExecCode_whenRefundDetailsLockCannotBeAcquired_doesNotMarkTreated(): void
    {
        $body = \json_encode(['id' => 'op_refund_1', 'execCode' => '9999', 'orderId' => '42', 'amount' => 500]);
        $details = ['refunds' => [['internal_id' => 77, 'id' => 'op_refund_1', 'amount' => 500]]];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');

        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturnCallback(
            static fn (string $key): bool => 'payplug_upc_refund_details_42' !== $key,
        );
        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->lock,
            $this->logger,
            new PayplugCardPersister($this->payplugCardFactory, $this->payplugCardRepository, $this->managerRegistry),
        );

        $payment = $this->payment(42, 1000, null, $details);
        $payment->expects(self::never())->method('setDetails');
        $this->paymentRepository->expects(self::never())->method('isTreated');
        $this->paymentRepository->expects(self::never())->method('save');
        $this->paymentRepository->expects(self::never())->method('markTreated');
        $this->orderStateMutator->expects(self::never())->method('apply');
        // Once for the "non-success outcome" log, once for the lock-contention log.
        $this->logger->expects(self::exactly(2))->method('error');

        $this->handler->treat($payment, $body, ['Authorization' => 'Bearer shared-secret']);
    }

    /**
     * A delayed/redelivered copy of the ORIGINAL payment-creation notification must never be
     * misclassified as a refund confirmation just because this payment also has an unresolved
     * (id: null) refund entry sitting in $details['refunds'] — the known payment-creation
     * operation id (hosted_fields_operation_id) excludes it from the unresolved-entry fallback.
     */
    public function testTreat_onRedeliveredPaymentNotification_isNotMisclassifiedAsTheUnresolvedRefund(): void
    {
        $body = \json_encode(['id' => 'op_payment_1', 'execCode' => '0000', 'orderId' => '42', 'amount' => 1000]);
        $details = [
            'hosted_fields_operation_id' => 'op_payment_1',
            'refunds' => [['internal_id' => 77, 'id' => null, 'amount' => 500]],
        ];

        $this->configurationRepository->method('get')->willReturn('Bearer shared-secret');
        $this->paymentRepository->method('isTreated')->with('op_payment_1')->willReturn(false);

        $this->paymentRepository->expects(self::once())->method('save');
        $this->paymentRepository->expects(self::once())->method('markTreated')->with('op_payment_1');
        $this->orderStateMutator->expects(self::once())->method('apply')->with('42', PaymentOutcome::PAID);

        $this->handler->treat($this->payment(42, 1000, null, $details), $body, ['Authorization' => 'Bearer shared-secret']);
    }
}
