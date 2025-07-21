<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentMethodTrait;
use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;
use Sylius\Component\Payment\Model\PaymentMethodTranslationInterface;
use Sylius\Component\Payment\Model\PaymentMethodTranslation;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment_method')]
class PaymentMethod extends BasePaymentMethod
{
    use PaymentMethodTrait;

    public function __construct()
    {
        parent::__construct();
        $this->cards = new ArrayCollection();
    }

    protected function createTranslation(): PaymentMethodTranslationInterface
    {
        return new PaymentMethodTranslation();
    }
}
