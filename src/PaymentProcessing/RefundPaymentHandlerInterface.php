<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Payplug\Resource\Refund;
use Sylius\Component\Core\Model\PaymentInterface;

interface RefundPaymentHandlerInterface
{
    public function handle(Refund $refund, PaymentInterface $payment): void;

    public function updatePaymentStatus(PaymentInterface $payment): void;
}
