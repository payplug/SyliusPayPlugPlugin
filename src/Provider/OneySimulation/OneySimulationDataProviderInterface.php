<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use Sylius\Component\Core\Model\OrderInterface;

interface OneySimulationDataProviderInterface
{
    public function getForCart(OrderInterface $cart): array;
}
