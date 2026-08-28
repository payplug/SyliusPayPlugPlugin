<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureAliasPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Resolver\SelectedCardResolver;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

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
    public function __construct(
        private SelectedCardResolver $selectedCardResolver,
    ) {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $details = $paymentRequest->getPayment()->getDetails();
        $selectedCard = $this->selectedCardResolver->resolve();

        if ($this->isAlreadyInFlight($details, $selectedCard)) {
            return new OfflineCapturePaymentRequest($paymentRequest->getId());
        }

        return null !== $selectedCard
            ? new CaptureAliasPaymentRequest($paymentRequest->getId())
            : new CaptureHostedPaymentRequest($paymentRequest->getId());
    }

    /**
     * True when a capture attempt for this exact payment/card pair was already created — either
     * via createPayment() (e.g. the shopper returned to /pay after a 3DS redirect) or as an
     * already-in-flight alias attempt for the same card.
     *
     * @param mixed[] $details
     */
    private function isAlreadyInFlight(array $details, ?Card $selectedCard): bool
    {
        if (\is_string($details['hosted_fields_created_at'] ?? null)) {
            return true;
        }

        if (\is_string($details['alias_payment_created_at'] ?? null)) {
            return $this->isAliasAttemptStillInFlight($details, $selectedCard);
        }

        return false;
    }

    // Split out of isAlreadyInFlight() to keep its own return count within SonarCloud's limit
    // (php:S1142).
    private function isAliasAttemptStillInFlight(array $details, ?Card $selectedCard): bool
    {
        // The customer switching to a *different, currently selected* saved card after an
        // earlier alias attempt was created for another one is NOT "in flight" — the caller
        // must fall through and re-evaluate as a fresh attempt, rather than silently
        // re-polling the stale one. But switching to "other"/no selection at all gives no new
        // card to justify a fresh attempt, so the still-unresolved earlier alias attempt stays
        // in flight rather than dispatching a second, independent capture for the same
        // payment (double-charge risk if the abandoned attempt later resolves too).
        if (null === $selectedCard) {
            return true;
        }

        return $selectedCard->getExternalId() === ($details['alias_id'] ?? null);
    }
}
