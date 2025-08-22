<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity;

use Doctrine\Common\Collections\Collection;

interface CardsOwnerInterface
{
    /**
     * @return Collection<\PayPlug\SyliusPayPlugPlugin\Entity\Card>
     */
    public function getCards(): Collection;

    public function addCard(Card $card): self;
}
