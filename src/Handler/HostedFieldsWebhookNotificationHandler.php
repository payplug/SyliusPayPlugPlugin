<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use PayPlug\SyliusPayPlugPlugin\Upc\CardDataFromPaymentMethodExtractor;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentOrderIdResolver;
use PayPlug\SyliusPayPlugPlugin\Upc\PayplugCardPersister;
use PayPlug\SyliusPayPlugPlugin\Upc\ResourceIdentifier;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Verifies and applies a Unified API (Hosted Fields) webhook notification against a Payment
 * already resolved by IpnAction, via PaymentRepositoryInterface::findOneByPayPlugPaymentId() —
 * which matches on the webhook's own payment id being present anywhere in Payment::details,
 * including the "hosted_fields_payment_id" key CaptureHostedPaymentRequestHandler stores there.
 *
 * The static, per-account IPN receiver counterpart to NotifyHostedPaymentRequestHandler, which
 * instead resolves its target Payment via a per-request PaymentRequest hash. Both share the same
 * verify/parse/idempotency primitives (WebhookNotificationHelper, IPaymentRepository); only the
 * resolution path differs. No merchant/account of ours has a way to configure a webhook secret
 * (see WebhookNotificationHelper::verifySignature()), so this handler's orderId/amount
 * cross-check against the resolved Payment is the only remaining protection against a
 * notification — genuine or forged — being applied to the wrong payment.
 *
 * Also called directly by StatusHostedPaymentRequestHandler's GET polling fallback (same
 * webhook-shaped payload, fetched instead of received) — the lock below is keyed by operationId
 * rather than by a caller-specific value so both callers serialize against each other: without
 * it, a poll and a genuinely concurrent webhook delivery for the same operation could both pass
 * isTreated() before either reaches markTreated().
 */
class HostedFieldsWebhookNotificationHandler
{
    // No admin field or other mechanism currently writes this key, so $expectedHeader below
    // always resolves empty and WebhookNotificationHelper::verifySignature() accepts every
    // notification unverified — see that method's docblock for why that's intentional here, not
    // a gap. Kept (rather than dropped) so a real secret, if one ever becomes configurable, is
    // still enforced without further changes on this end.
    private const CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER = 'payplug_webhook_authorization_header';

    private const LOCK_KEY_PREFIX = 'payplug_upc_treat_';

    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
        private IConfigurationRepository $configurationRepository,
        private ILock $lock,
        private LoggerInterface $logger,
        private PayplugCardPersister $cardPersister,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidNotificationException if the notification fails signature verification or
     *                                       parsing — the caller is expected to catch this, same
     *                                       as it already does for the legacy SDK's PayplugException.
     */
    public function treat(PaymentInterface $payment, string $rawBody, array $headers): void
    {
        $expectedHeader = $this->configurationRepository->get(self::CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER) ?? '';
        $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);

        if (!$this->matchesPayment($payment, $operationData)) {
            return;
        }

        $lockKey = self::LOCK_KEY_PREFIX . $operationData->operationId;
        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            // Another delivery/poll for the same operation is already being processed — whichever
            // holds the lock applies the outcome, nothing more to do here.
            return;
        }

        try {
            if ($this->paymentRepository->isTreated($operationData->operationId)) {
                return;
            }

            $this->paymentRepository->save($operationData);
            $this->orderStateMutator->apply(ResourceIdentifier::toString($payment->getId()), $operationData->outcome);
            $this->paymentRepository->markTreated($operationData->operationId);

            if (PaymentOutcome::PAID === $operationData->outcome) {
                $this->maybeSaveCard($payment, $rawBody);
            }
        } finally {
            $this->lock->release($lockKey);
        }
    }

    // A 3DS-challenge capture never gets an alias back synchronously (CaptureHostedPaymentRequestHandler
    // only sees one on a direct, frictionless success) — this webhook, fired once the challenge is
    // validated, is the only place a 3DS payment's card ever gets saved. The alias/card metadata
    // itself is already in $rawBody: confirmed the same paymentMethod.{id, card, details} shape as
    // the operation resource CaptureHostedPaymentRequestHandler fetches separately, so no extra API
    // call is needed here.
    private function maybeSaveCard(PaymentInterface $payment, string $rawBody): void
    {
        $details = $payment->getDetails();
        if (true !== ($details['hosted_fields_save_card'] ?? false)) {
            return;
        }

        $method = $payment->getMethod();
        if (null === $method) {
            return;
        }

        $cardData = CardDataFromPaymentMethodExtractor::extractFromDecoded(\json_decode($rawBody, true));
        $aliasId = $cardData['aliasId'] ?? null;
        if (null === $aliasId) {
            $this->logger->error('[PayPlug][UPC] Save-card was requested but the webhook notification carried no alias id.', [
                'sylius_payment_id' => $payment->getId(),
            ]);

            return;
        }

        $this->cardPersister->persist($aliasId, $payment, $method, $details, $cardData);
    }

    // Split out of treat() to keep its own return count within SonarCloud's limit (php:S1142) —
    // both branches here mean "nothing to apply," they just differ in whether that's expected
    // (still-pending) or a problem worth logging over (mismatch).
    private function matchesPayment(PaymentInterface $payment, OperationData $operationData): bool
    {
        if (PaymentOutcome::THREE_DS_PENDING === $operationData->outcome) {
            // Not a final outcome — leave the payment as-is and, crucially, do not touch
            // isTreated()/markTreated(): a later, final notification for this same operation
            // must still be free to apply once it arrives. The 0001-is-pending knowledge itself
            // now lives in payplug/unified-plugin-core's ExecCodeMapper (see its docblock for the
            // full execcode-catalog reasoning), not duplicated here.
            return false;
        }

        $expectedOrderId = PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId());
        if ($operationData->orderId !== $expectedOrderId || $operationData->amount !== $payment->getAmount()) {
            $this->logger->error('[PayPlug][UPC] Hosted Fields webhook notification does not match the payment it was resolved against.', [
                'sylius_payment_id' => $payment->getId(),
                'expected_order_id' => $expectedOrderId,
                'received_order_id' => $operationData->orderId,
                'expected_amount' => $payment->getAmount(),
                'received_amount' => $operationData->amount,
            ]);

            return false;
        }

        return true;
    }
}
