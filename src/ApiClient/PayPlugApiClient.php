<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Resource\Payment;
use Payplug\Resource\Refund;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    public function initialise(string $secretKey): void
    {
        \Payplug\Payplug::setSecretKey($secretKey);
    }

    public function createPayment(array $data): Payment
    {
        return \Payplug\Payment::create($data);
    }

    public function refundPayment(string $paymentId): Refund
    {
        return \Payplug\Refund::create($paymentId);
    }

    public function treat($input)
    {
        return \Payplug\Notification::treat($input);
    }

    public function retrieve(string $paymentId): Payment
    {
        return \Payplug\Payment::retrieve($paymentId);
    }
}
