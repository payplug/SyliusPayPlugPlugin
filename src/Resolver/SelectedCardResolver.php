<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the customer's selected saved card from the checkout session — shared by
 * CaptureHostedPaymentRequestCommandProvider (routing decision) and CaptureAliasPaymentRequestHandler
 * (actual capture), so both resolve it the same way.
 */
final class SelectedCardResolver
{
    public function __construct(
        private RequestStack $requestStack,
        private RepositoryInterface $payplugCardRepository,
    ) {
    }

    public function resolve(): ?Card
    {
        $cardId = $this->requestStack->getSession()->get('payplug_payment_method');
        if (null === $cardId || PayPlugGatewayFactory::CARD_CHOICE_OTHER === $cardId) {
            return null;
        }

        $card = $this->payplugCardRepository->find($cardId);

        return $card instanceof Card ? $card : null;
    }
}
