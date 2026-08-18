<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Handler;

use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
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
 * resolution path differs — so unlike that handler, this one applies the outcome against the
 * Payment id already resolved by the caller rather than against OperationData's own orderId
 * field (which carries the order *number* PayPlug was given at creation time, not a Sylius
 * Payment id) — there is nothing left to resolve here.
 */
class HostedFieldsWebhookNotificationHandler
{
    // Mirrors NotifyHostedPaymentRequestHandler's own documented gap: nothing currently writes
    // this configuration key, so $expectedHeader always resolves empty and every notification is
    // rejected by WebhookNotificationHelper::verifySignature() until something does.
    private const CONFIG_KEY_WEBHOOK_AUTHORIZATION_HEADER = 'payplug_webhook_authorization_header';

    public function __construct(
        private IPaymentRepository $paymentRepository,
        private IOrderStateMutator $orderStateMutator,
        private IConfigurationRepository $configurationRepository,
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

        if ($this->paymentRepository->isTreated($operationData->operationId)) {
            return;
        }

        $this->paymentRepository->save($operationData);
        $this->orderStateMutator->apply(self::idToString($payment->getId()), $operationData->outcome);
        $this->paymentRepository->markTreated($operationData->operationId);
    }

    private static function idToString(mixed $id): string
    {
        if (!\is_int($id) && !\is_string($id)) {
            throw new \LogicException('Unexpected non-scalar resource identifier.');
        }

        return (string) $id;
    }
}
