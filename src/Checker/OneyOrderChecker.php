<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever;
use Sylius\Component\Core\Model\OrderInterface;

final class OneyOrderChecker
{
    public function __construct(private OneyInvalidDataRetriever $invalidDataRetriever)
    {
    }

    /**
     * Validate if phone number are setted and are mobile, and if address mail don't contains + characters.
     */
    public function isOrderInfoCorrect(OrderInterface $order): bool
    {
        return [] === $this->invalidDataRetriever->getForOrder($order);
    }
}
