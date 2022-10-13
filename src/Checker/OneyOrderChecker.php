<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever;
use Sylius\Component\Core\Model\OrderInterface;

final class OneyOrderChecker
{
    /** @var \PayPlug\SyliusPayPlugPlugin\Validator\OneyInvalidDataRetriever */
    private $invalidDataRetriever;

    public function __construct(OneyInvalidDataRetriever $invalidDataRetriever)
    {
        $this->invalidDataRetriever = $invalidDataRetriever;
    }

    /**
     * Validate if phone number are setted and are mobile, and if address mail don't contains + characters.
     */
    public function isOrderInfoCorrect(OrderInterface $order): bool
    {
        return 0 === \count($this->invalidDataRetriever->getForOrder($order));
    }
}
