<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentTrait;
use Sylius\Component\Core\Model\Payment as BasePayment;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_payment")
 */
#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment')]
class Payment extends BasePayment
{
    use PaymentTrait;

    public function __construct()
    {
        parent::__construct();
        $this->refundHistories = new ArrayCollection();
    }
}
