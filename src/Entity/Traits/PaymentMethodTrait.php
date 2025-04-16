<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity\Traits;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;

trait PaymentMethodTrait
{
    /** @ORM\OneToMany(targetEntity=Card::class, mappedBy="paymentMethod", orphanRemoval=true) */
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'paymentMethod', orphanRemoval: true)]
    protected $cards;

    /**
     * @return Collection|Card[]
     */
    public function getCards(): Collection
    {
        return $this->cards->filter(function (Card $card): bool {
            $secretKeyPrefix = \substr($card->getPaymentMethod()->getGatewayConfig()->getConfig()['secretKey'], 0, 7);
            if (
                ($card->isLive() && PayPlugApiClientInterface::LIVE_KEY_PREFIX === $secretKeyPrefix) ||
                (!$card->isLive() && PayPlugApiClientInterface::TEST_KEY_PREFIX === $secretKeyPrefix)
            ) {
                return true;
            }

            return false;
        });
    }

    public function addCard(Card $card): self
    {
        if (!$this->cards->contains($card)) {
            $this->cards[] = $card;
            $card->setCustomer($this);
        }

        return $this;
    }
}
