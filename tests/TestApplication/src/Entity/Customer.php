<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use PayPlug\SyliusPayPlugPlugin\Entity\Traits\CustomerTrait;
use Sylius\Component\Core\Model\Customer as BaseCustomer;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_customer')]
class Customer extends BaseCustomer
{
    use CustomerTrait;
}
