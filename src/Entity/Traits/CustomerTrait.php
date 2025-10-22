<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity\Traits;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;

trait CustomerTrait
{
    /**
     * @ORM\OneToMany(targetEntity=Card::class, mappedBy="customer", orphanRemoval=true)
     *
     * @ORM\OrderBy({"id" = "DESC"})
     */
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'customer', orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    protected $cards;

    /**
     * @return Collection<Card>
     */
    public function getCards(): Collection
    {
        return $this->cards->filter(function (Card $card): bool {
            $isLivePaymentMethod = $card->getPaymentMethod()->getGatewayConfig()?->getConfig()['live'] ?? false;

            return ($card->isLive() && true === $isLivePaymentMethod) ||
                (!$card->isLive() && false === $isLivePaymentMethod);
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
