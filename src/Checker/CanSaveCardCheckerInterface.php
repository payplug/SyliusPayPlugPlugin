<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use Sylius\Component\Core\Model\PaymentMethodInterface;

interface CanSaveCardCheckerInterface
{
    public function isAllowed(PaymentMethodInterface $paymentMethod): bool;
}
