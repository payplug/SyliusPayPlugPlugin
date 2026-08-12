<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\ResolvesSelectedCardTrait;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Not tagged for direct DI collection: dispatched to only by
 * CapturePaymentRequestCommandProvider, when the payment method is `payplug` with Hosted Fields
 * selected (see PayPlugGatewayFactory::isHostedFieldsConfig()).
 *
 * Branches on the *existing* checkout session value PaymentTypeExtension already sets for every
 * PayPlug gateway payment (`payplug_payment_method`, a saved Card id or the sentinel "other") —
 * introduced long before Hosted Fields existed, for the redirected one-click flow, but read here
 * unchanged: it is set regardless of which PayPlug display mode is active. A real card id selects
 * the "pay with an existing alias" path (CaptureAliasPaymentRequest); "other"/no selection falls
 * through to the token-driven Hosted Fields path, unchanged from before this class existed.
 */
final class CaptureHostedPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    use ResolvesSelectedCardTrait;

    public function __construct(
        private RequestStack $requestStack,
        private RepositoryInterface $payplugCardRepository,
    ) {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $details = $paymentRequest->getPayment()->getDetails();
        $selectedCard = $this->resolveSelectedCard();

        if (\is_string($details['hosted_fields_created_at'] ?? null)) {
            // Already created via createPayment() (e.g. the shopper returned to /pay after a 3DS
            // redirect) — do not create it again.
            return new OfflineCapturePaymentRequest($paymentRequest->getId());
        }

        if (\is_string($details['alias_payment_created_at'] ?? null)) {
            $capturedAliasId = $details['alias_id'] ?? null;
            if (null !== $selectedCard && $selectedCard->getExternalId() === $capturedAliasId) {
                // Same card as the already-in-flight attempt (e.g. returning from a 3DS redirect) —
                // do not create it again.
                return new OfflineCapturePaymentRequest($paymentRequest->getId());
            }
            // The customer switched to a different card (or "other") after an earlier alias attempt
            // was already created for a different one — fall through and re-evaluate as a fresh
            // attempt below, rather than silently re-polling the stale one.
        }

        if (null !== $selectedCard) {
            return new CaptureAliasPaymentRequest($paymentRequest->getId());
        }

        return new CaptureHostedPaymentRequest($paymentRequest->getId());
    }
}
