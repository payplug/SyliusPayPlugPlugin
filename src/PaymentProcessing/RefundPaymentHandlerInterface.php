<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Payplug\Resource\Refund;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\RefundPlugin\Command\RefundUnits;

interface RefundPaymentHandlerInterface
{
    public function handle(Refund $refund, PaymentInterface $payment): RefundUnits;

    public function updatePaymentStatus(PaymentInterface $payment): void;
}
