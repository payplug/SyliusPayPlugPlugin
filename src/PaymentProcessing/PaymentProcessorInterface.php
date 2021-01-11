<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Sylius\Component\Core\Model\PaymentInterface;

interface PaymentProcessorInterface
{
    public function process(PaymentInterface $payment): void;

    public function processWithAmount(PaymentInterface $payment, int $amount, int $refundId): void;
}
