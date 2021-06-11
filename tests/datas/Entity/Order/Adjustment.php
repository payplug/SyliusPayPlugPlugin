<?php

declare(strict_types=1);

namespace App\Entity\Order;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Order\Model\Adjustment as BaseAdjustment;
use Sylius\RefundPlugin\Entity\AdjustmentInterface as RefundAdjustmentInterface;
use Sylius\RefundPlugin\Entity\AdjustmentTrait;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_adjustment")
 */
class Adjustment extends BaseAdjustment implements RefundAdjustmentInterface
{
    use AdjustmentTrait;
}
