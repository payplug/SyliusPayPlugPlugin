<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;

trait PaymentTrait
{
    /** @ORM\OneToMany(targetEntity=RefundHistory::class, mappedBy="payment", orphanRemoval=true) */
    #[ORM\OneToMany(targetEntity: RefundHistory::class, mappedBy: 'payment', orphanRemoval: true)]
    protected $refundHistories;
}
