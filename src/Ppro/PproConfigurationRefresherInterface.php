<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface PproConfigurationRefresherInterface
{
    /**
     * Stores the PayPlug limits of a PPRO payment method in its gateway config, re-read from the
     * database first so that only the `payment_method` block is replaced. Never throws: on a failure
     * to fetch the limits the stored config is left untouched, and the result says it was not
     * refreshed (a failed flush also closes the entity manager).
     */
    public function refresh(PaymentMethodInterface $paymentMethod, bool $flush = true): PproRefreshResult;

    /**
     * Same as refresh(), unless the limits stored for the current environment were refreshed a few
     * minutes ago: they are then kept as they are, without calling PayPlug or writing anything.
     */
    public function refreshIfStale(PaymentMethodInterface $paymentMethod, bool $flush = true): PproRefreshResult;
}
