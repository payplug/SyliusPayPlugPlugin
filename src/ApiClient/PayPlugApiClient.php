<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Resource\Payment;
use Payplug\Resource\Refund;

class PayPlugApiClient implements PayPlugApiClientInterface
{
    /** @var string|null */
    private $notificationUrlDev;

    public function initialise(string $secretKey, ?string $notificationUrlDev = null): void
    {
        \Payplug\Payplug::setSecretKey($secretKey);

        $this->notificationUrlDev = $notificationUrlDev;
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

    public function getNotificationUrlDev(): ?string
    {
        return $this->notificationUrlDev;
    }
}
