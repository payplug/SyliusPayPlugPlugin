<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Handler;

use PayPlug\SyliusPayPlugPlugin\Handler\HostedFieldsWebhookNotificationHandler;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class HostedFieldsWebhookNotificationHandlerTest extends TestCase
{
    private IPaymentRepository&MockObject $paymentRepository;

    private IOrderStateMutator&MockObject $orderStateMutator;

    private IConfigurationRepository&MockObject $configurationRepository;

    private ILock&MockObject $lock;

    private LoggerInterface&MockObject $logger;

    private HostedFieldsWebhookNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(IPaymentRepository::class);
        $this->orderStateMutator = $this->createMock(IOrderStateMutator::class);
        $this->configurationRepository = $this->createMock(IConfigurationRepository::class);
        $this->lock = $this->createMock(ILock::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new HostedFieldsWebhookNotificationHandler(
            $this->paymentRepository,
            $this->orderStateMutator,
            $this->configurationRepository,
            $this->lock,
            $this->logger,
        );
    }

    private function payment(int $id = 42, int $amount = 1000, ?string $orderNumber = null): PaymentInterface&MockObject
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn($id);
        $payment->method('getAmount')->willReturn($amount);

        if (null !== $orderNumber) {
            $order = $this->createMock(OrderInterface::class);
            $order->method('getNumber')->willReturn($orderNumber);
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
}
