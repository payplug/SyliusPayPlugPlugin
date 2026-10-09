<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface PayPlugApiClientFactoryInterface
{
    /**
     * The only way to obtain a client from application code. Resolving one by factory name is
     * deliberately absent: since PRE-3628 several enabled gateway configs may share a factory name
     * — one per channel — so a name-based lookup returns an arbitrary one of them and can sign a
     * request for channel A with channel B's account credentials. Keeping that signature off this
     * interface makes the compiler, rather than review, the guard against reintroducing it.
     */
    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): PayPlugApiClientInterface;
}
