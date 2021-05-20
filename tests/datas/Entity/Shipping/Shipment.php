<?php

declare(strict_types=1);

namespace App\Entity\Shipping;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\AdjustmentInterface as BaseAdjustmentInterface;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Sylius\RefundPlugin\Entity\ShipmentTrait;
use Sylius\RefundPlugin\Entity\ShipmentInterface as RefundShipmentInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_shipment")
 */
class Shipment extends BaseShipment implements RefundShipmentInterface
{
    use ShipmentTrait;

    public function __construct()
    {
        parent::__construct();

        /** @var ArrayCollection<array-key, BaseAdjustmentInterface> $this->adjustments */
        $this->adjustments = new ArrayCollection();
    }
}
