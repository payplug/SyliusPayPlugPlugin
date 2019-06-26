<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Sylius\Component\Core\Model\PaymentInterface;

interface PaymentProcessorInterface
{
    public function process(PaymentInterface $payment): void;
}
