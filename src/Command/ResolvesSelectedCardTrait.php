<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;

/**
 * Shared by CaptureHostedPaymentRequestCommandProvider (routing decision) and
 * CaptureAliasPaymentRequestHandler (actual capture), so both resolve the customer's selected
 * saved card the same way. Requires the host class to have $requestStack and
 * $payplugCardRepository properties (RequestStack and RepositoryInterface respectively).
 */
trait ResolvesSelectedCardTrait
{
    private function resolveSelectedCard(): ?Card
    {
        $cardId = $this->requestStack->getSession()->get('payplug_payment_method');
        if (null === $cardId || PayPlugGatewayFactory::CARD_CHOICE_OTHER === $cardId) {
            return null;
        }

        $card = $this->payplugCardRepository->find($cardId);

        return $card instanceof Card ? $card : null;
    }
}
