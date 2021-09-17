<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity\Traits;

use Doctrine\Common\Collections\Collection;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;

trait CustomerTrait
{
    /**
     * @ORM\OneToMany(targetEntity=Card::class, mappedBy="customer", orphanRemoval=true)
     * @ORM\OrderBy({"id" = "DESC"})
     */
    protected $cards;

    /**
     * @return Collection|Card[]
     */
    public function getCards(): Collection
    {
        return $this->cards->filter(function (Card $card): bool {
            $now = new \DateTime();
            $now->setDate($now->format('Y'), $now->format('m'), 1);
            $expires = \DateTime::createFromFormat('n/Y', $card->getExpirationMonth() . '/' . $card->getExpirationYear());

            if ($expires < $now) {
                return false;
            }

            $secretKeyPrefix = \substr($card->getPaymentMethod()->getGatewayConfig()->getConfig()['secretKey'], 0, 7);
            if (($card->isLive() && $secretKeyPrefix === PayPlugApiClientInterface::LIVE_KEY_PREFIX) ||
                (!$card->isLive() && $secretKeyPrefix === PayPlugApiClientInterface::TEST_KEY_PREFIX)) {
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
