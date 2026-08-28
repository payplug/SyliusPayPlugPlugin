<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Exception;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentOrderIdResolver;
use PayPlug\SyliusPayPlugPlugin\Upc\RefundCreatorInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\RefundDetailsLockKey;
use PayplugUnifiedCore\Contracts\ILock;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

#[Autoconfigure(public: true)]
final class RefundPaymentProcessor implements PaymentProcessorInterface
{
    private const REFUND_LOCK_TTL_SECONDS = 30;

    private PayPlugApiClientInterface $payPlugApiClient;

    public function __construct(
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private RepositoryInterface $refundPaymentRepository,
        private RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private RefundCreatorInterface $refundCreator,
        private ILock $lock,
    ) {
    }

    #[AsCompletedListener(workflow: PaymentTransitions::GRAPH, transition: PaymentTransitions::TRANSITION_REFUND)]
    public function onRefundCompleteTransitionEvent(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof PaymentInterface) {
            return;
        }

        $this->process($subject);
    }

    public function process(PaymentInterface $payment): void
    {
        $this->prepare($payment);

        if (self::isHostedFields($payment)) {
            $this->processHostedFields($payment);

            return;
        }

        $details = $payment->getDetails();
        Assert::string($details['payment_id']);

        try {
            $this->payPlugApiClient->refundPayment($details['payment_id']);
        } catch (Exception $exception) {
            $message = $exception->getMessage();

            $this->logger->error('[PayPlug] RefundHistory Payment', ['error' => $message]);

            throw new UpdateHandlingException();
        }
    }

    public function processWithAmount(PaymentInterface $payment, int $amount, int $refundId): void
    {
        $this->prepare($payment);

        if (self::isHostedFields($payment)) {
            $this->processHostedFieldsWithAmount($payment, $amount, $refundId);

            return;
        }

        $details = $payment->getDetails();
        Assert::string($details['payment_id']);

        try {
            $refund = $this->payPlugApiClient->refundPaymentWithAmount($details['payment_id'], $amount, $refundId);
            $refunds = $details['refunds'] ?? [];
            $refunds[] = [
                'internal_id' => $refundId,
                'id' => $refund->id,
                'amount' => $refund->amount,
                'meta_data' => $refund->metadata,
            ];
            $details['refunds'] = $refunds;
            $payment->setDetails($details);

            /** @var RefundPayment $refundPayment */
            $refundPayment = $this->refundPaymentRepository->findOneBy(['id' => $refundId]);
            $refundHistory = new RefundHistory();
            $refundHistory
                ->setExternalId(null)
                ->setPayment($payment)
                ->setRefundPayment($refundPayment)
                ->setValue($amount)
                ->setProcessed(true)
            ;
            $this->payplugRefundHistoryRepository->add($refundHistory);
        } catch (Exception $exception) {
            $message = $exception->getMessage();

            $this->logger->error('[PayPlug] RefundHistory Payment', ['error' => $message]);

            throw new UpdateHandlingException();
        }
    }

    /**
     * UHF counterpart of process() — same full-refund shape, but via UPC's createRefund() rather
     * than the legacy PayPlugApiClient. No RefundHistory bookkeeping here, matching process()'s
     * own behavior (only processWithAmount() persists one) — but the refund's own operation id is
     * still recorded under $details['refunds'] (internal_id null: there's no Sylius
     * RefundPayment/$refundId in this flow), since HostedFieldsWebhookNotificationHandler resolves
     * the payment for a refund's async webhook confirmation by matching against ids present
     * somewhere in Payment::details — without this, that confirmation could never be resolved.
     *
     * Guarded by $lock (same ILock contract HostedFieldsWebhookNotificationHandler already uses),
     * keyed by RefundDetailsLockKey — the same key processHostedFieldsWithAmount() uses for this
     * same payment, so a full and a partial refund triggered concurrently on it serialize against
     * each other too, not just two calls of the same kind; it's also the same key
     * HostedFieldsWebhookNotificationHandler::markMatchedRefundAsFailed() acquires before its own
     * write to this same $details['refunds'] array, so a refund creation here (which spans the
     * createRefund() network call) and a webhook concurrently flagging an earlier refund failed
     * can't interleave their read-modify-write and silently drop one of the two writes. Unlike the
     * legacy flow, which forwards Sylius's own refund id to PayPlug as a de-facto idempotency key,
     * UPC's createRefund() has no idempotency-key parameter at all — a concurrent second call for
     * this same payment (e.g. a double form submission, or a full refund racing a partial one)
     * would otherwise be free to trigger a second, real refund on the account. There's no
     * RefundHistory/refundId to check-then-act on here (full refunds don't create one, mirroring
     * process()'s legacy behavior), so the lock is the only guard available for this path.
     */
    private function processHostedFields(PaymentInterface $payment): void
    {
        // Deliberately outside the try/catch below, mirroring the legacy path's own
        // Assert::string($details['payment_id']) — a payment reaching here without this detail
        // set raises a raw InvalidArgumentException rather than UpdateHandlingException.
        Assert::string($payment->getDetails()['hosted_fields_payment_id']);
        $originalAmount = $payment->getAmount();
        if (null === $originalAmount) {
            throw new \LogicException('Payment amount is not set.');
        }

        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();
        $lockKey = RefundDetailsLockKey::forPaymentId($payment->getId());

        $this->runLockedRefund($lockKey, ['sylius_payment_id' => $payment->getId()], function () use ($payment, $method, $originalAmount): void {
            // Re-read now that the lock is held, not a snapshot taken before it: the lock is what
            // actually keeps this read-modify-write from racing
            // HostedFieldsWebhookNotificationHandler::markMatchedRefundAsFailed()'s own — see this
            // method's own docblock above.
            $details = $payment->getDetails();
            $externalId = $this->createRefundOperation(
                $method,
                $details['hosted_fields_payment_id'],
                PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId()),
                null,
                ['sylius_payment_id' => $payment->getId()],
            );

            $refunds = self::normalizeRefunds($details);
            // Omitting $amount to createRefund() above refunds the payment's full REMAINING
            // amount (per UnifiedApiPaymentService::createRefund()'s own docblock), not
            // $originalAmount — those two only coincide when no prior refund exists yet.
            // Subtracting whatever this plugin already recorded as refunded (computed BEFORE
            // appending the new entry below) keeps this one accurate, which matchesPayment() then
            // relies on to ever match this refund's own webhook confirmation.
            $refundedAmount = $originalAmount - self::sumRecordedRefunds($refunds);
            self::appendRefundEntry($payment, $details, $refunds, null, $externalId, $refundedAmount);
        });
    }

    /**
     * Shared by processHostedFields()/processHostedFieldsWithAmount(): both acquire $lockKey (see
     * each method's own docblock for why a lock is needed at all here), run $refund inside it, and
     * convert any \Exception it throws — including one from $refundCreator->createRefund() itself
     * — into the same logged UpdateHandlingException, releasing the lock either way.
     *
     * @param mixed[] $lockFailureContext
     */
    private function runLockedRefund(string $lockKey, array $lockFailureContext, \Closure $refund): void
    {
        if (!$this->lock->acquire($lockKey, self::REFUND_LOCK_TTL_SECONDS)) {
            $this->logger->error('[PayPlug][UPC] Refund already in progress for this payment, refusing a concurrent call.', $lockFailureContext);

            throw new UpdateHandlingException();
        }

        try {
            $refund();
        } catch (Exception $exception) {
            $this->logger->error('[PayPlug][UPC] Refund Payment', ['error' => $exception->getMessage()]);

            throw new UpdateHandlingException();
        } finally {
            $this->lock->release($lockKey);
        }
    }

    /**
     * Actionable, not just informational: without an operation id, the eventual async webhook
     * confirmation for this refund can never be matched back to it (see
     * HostedFieldsWebhookNotificationHandler::findMatchingRefundAmount()) and either gets dropped
     * or, worse, misapplied as a plain payment confirmation.
     *
     * @param mixed[] $context
     */
    private function logIfOperationIdMissing(?string $externalId, string $responseBody, array $context): void
    {
        if (null !== $externalId) {
            return;
        }

        $this->logger->error('[PayPlug][UPC] Refund succeeded but the response carried no operationIds.', [
            ...$context,
            'response_body' => $responseBody,
        ]);
    }

    /**
     * Shared by processHostedFields()/processHostedFieldsWithAmount(): calls
     * $refundCreator->createRefund() and extracts the refund's own operation id from the response
     * (logging via logIfOperationIdMissing() when the response carried none) — the one piece
     * genuinely identical between the two callers, $amount aside (null here means a full refund;
     * a given value means a partial one).
     *
     * @param mixed[] $logContext
     */
    private function createRefundOperation(
        PaymentMethodInterface $method,
        string $hostedFieldsPaymentId,
        string $orderId,
        ?int $amount,
        array $logContext,
    ): ?string {
        $response = $this->refundCreator->createRefund($method, $hostedFieldsPaymentId, $orderId, $amount);

        $externalId = self::extractFirstOperationId($response['body']);
        $this->logIfOperationIdMissing($externalId, $response['body'], $logContext);

        return $externalId;
    }

    /**
     * @param mixed[] $details
     *
     * @return mixed[]
     */
    private static function normalizeRefunds(array $details): array
    {
        $refunds = $details['refunds'] ?? [];

        return \is_array($refunds) ? $refunds : [];
    }

    /**
     * Shared by processHostedFields()/processHostedFieldsWithAmount(): appends one entry to
     * $refunds (already normalized via normalizeRefunds()) and persists the resulting
     * $details['refunds'] via setDetails() — the bookkeeping
     * HostedFieldsWebhookNotificationHandler later matches a refund's async webhook confirmation
     * against (see its own findMatchingRefundAmount()).
     *
     * @param mixed[] $details
     * @param mixed[] $refunds
     */
    private static function appendRefundEntry(
        PaymentInterface $payment,
        array $details,
        array $refunds,
        ?int $internalId,
        ?string $externalId,
        int $amount,
    ): void {
        $refunds[] = [
            'internal_id' => $internalId,
            'id' => $externalId,
            'amount' => $amount,
        ];
        $details['refunds'] = $refunds;
        $payment->setDetails($details);
    }

    /**
     * @param mixed[] $refunds
     *
     * Skips any entry HostedFieldsWebhookNotificationHandler::markMatchedRefundAsFailed() flagged
     * 'failed' => true — its synchronous createRefund() call was accepted (2xx), but the async
     * confirmation later reported the refund itself never actually completed, so that money was
     * never moved and must not count against the remaining balance a subsequent full refund
     * derives from this sum.
     */
    private static function sumRecordedRefunds(array $refunds): int
    {
        $total = 0;
        foreach ($refunds as $refund) {
            if (!\is_array($refund) || true === ($refund['failed'] ?? false)) {
                continue;
            }

            $amount = $refund['amount'] ?? null;
            $total += \is_int($amount) ? $amount : 0;
        }

        return $total;
    }

    /**
     * UHF counterpart of processWithAmount(). The refund's own operation id — extracted from the
     * createRefund() response's operationIds[0], same convention resolveHostedFieldsIds() uses
     * for a payment's operationIds[0] at creation time — takes the place of the legacy SDK
     * Refund object's ->id in $details['refunds']. RefundHistory::externalId stays null, exactly
     * like the legacy flow: that field is reserved for the id an async webhook confirmation would
     * carry, not the id already known synchronously here.
     *
     * Unlike the legacy flow (which forwards Sylius's own $refundId to the API as a de-facto
     * idempotency key), UPC's createRefund() has no idempotency-key parameter at all — so, on top
     * of a RefundHistory already recorded for this $refundId being checked for up front (before
     * calling createRefund() again), the whole check-then-act sequence is now also guarded by
     * $lock, keyed by RefundDetailsLockKey (same key processHostedFields() uses for this same
     * payment, so a partial and a full refund racing each other also serialize, not just two
     * partial refunds — and the same key HostedFieldsWebhookNotificationHandler acquires around
     * its own write to this $details['refunds'] array, see processHostedFields()'s own docblock):
     * without it, two concurrent calls could both pass the RefundHistory check before either one
     * persists, and both would go on to call createRefund() — double-refunding money that a plain
     * "check first" can't prevent, only a lock actually serializing the attempts can.
     */
    private function processHostedFieldsWithAmount(PaymentInterface $payment, int $amount, int $refundId): void
    {
        // Deliberately outside the try/catch below — see processHostedFields()'s own comment.
        Assert::string($payment->getDetails()['hosted_fields_payment_id']);

        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();
        $lockKey = RefundDetailsLockKey::forPaymentId($payment->getId());

        $this->runLockedRefund(
            $lockKey,
            ['sylius_payment_id' => $payment->getId(), 'refund_id' => $refundId],
            function () use ($payment, $method, $amount, $refundId): void {
                /** @var RefundPayment $refundPayment */
                $refundPayment = $this->refundPaymentRepository->findOneBy(['id' => $refundId]);

                if ($this->payplugRefundHistoryRepository->findOneBy(['refundPayment' => $refundPayment]) instanceof RefundHistory) {
                    $this->logger->info('[PayPlug][UPC] Refund already recorded for this refund id, skipping duplicate call.', ['refund_id' => $refundId]);

                    return;
                }

                // Re-read now that the lock is held — see processHostedFields()'s own comment on
                // its equivalent re-read.
                $details = $payment->getDetails();
                $externalId = $this->createRefundOperation(
                    $method,
                    $details['hosted_fields_payment_id'],
                    PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId()),
                    $amount,
                    ['refund_id' => $refundId],
                );

                self::appendRefundEntry($payment, $details, self::normalizeRefunds($details), $refundId, $externalId, $amount);

                $refundHistory = new RefundHistory();
                $refundHistory
                    ->setExternalId(null)
                    ->setPayment($payment)
                    ->setRefundPayment($refundPayment)
                    ->setValue($amount)
                    ->setProcessed(true)
                ;
                $this->payplugRefundHistoryRepository->add($refundHistory);
            },
        );
    }

    private static function extractFirstOperationId(string $body): ?string
    {
        $decoded = \json_decode($body, true);
        $operationIds = \is_array($decoded) ? ($decoded['operationIds'] ?? null) : null;
        $operationId = \is_array($operationIds) ? ($operationIds[0] ?? null) : null;

        return \is_string($operationId) && '' !== $operationId ? $operationId : null;
    }

    private static function isHostedFields(PaymentInterface $payment): bool
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        return PayPlugGatewayFactory::isHostedFieldsConfig($paymentMethod->getGatewayConfig());
    }

    private function prepare(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        $details = $payment->getDetails();

        if (
            !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
            !\in_array($factoryName = $paymentMethod->getGatewayConfig()->getFactoryName(), [
                PayPlugGatewayFactory::FACTORY_NAME,
                OneyGatewayFactory::FACTORY_NAME,
                BancontactGatewayFactory::FACTORY_NAME,
                ApplePayGatewayFactory::FACTORY_NAME,
                AmericanExpressGatewayFactory::FACTORY_NAME,
                ScalapayGatewayFactory::FACTORY_NAME,
                WeroGatewayFactory::FACTORY_NAME,
            ], true)
        ) {
            return;
        }

        // UHF has no "payment_id" detail (see resolveHostedFieldsIds() in
        // CaptureHostedPaymentRequestHandler — it stores hosted_fields_payment_id instead), so the
        // check below would otherwise misfire the "refunded locally only" flash message on every
        // UHF refund even though process()/processWithAmount() now genuinely call the Unified API
        // for it. The legacy $payPlugApiClient this method would otherwise build below is unused
        // by the UHF path, so skipping it here also avoids an unnecessary token mint.
        if (self::isHostedFields($payment)) {
            return;
        }

        if (!isset($details['payment_id'])) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'info',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.payment_refund_locally'),
            );

            return;
        }

        $this->logger->info('[PayPlug] Start refund payment', ['payment_id' => $details['payment_id']]);

        $this->payPlugApiClient = $this->apiClientFactory->createForPaymentMethod($paymentMethod);
    }
}
