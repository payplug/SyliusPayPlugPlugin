<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface PayPlugApiClientFactoryInterface
{
    public function create(string $factoryName, ?string $key = null): PayPlugApiClientInterface;

    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): PayPlugApiClientInterface;
}
