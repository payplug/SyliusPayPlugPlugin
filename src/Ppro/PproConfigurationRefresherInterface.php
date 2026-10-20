<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface PproConfigurationRefresherInterface
{
    /**
     * Stores the PayPlug limits of a PPRO payment method in its gateway config. Never throws: on
     * any failure the stored config is left untouched and the result says it was not refreshed.
     */
    public function refresh(PaymentMethodInterface $paymentMethod, bool $flush = true): PproRefreshResult;
}
