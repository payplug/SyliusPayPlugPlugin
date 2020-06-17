<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;
use Webmozart\Assert\Assert;

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
        $payment = \Payplug\Payment::create($data);
        Assert::isInstanceOf($payment, Payment::class);

        return $payment;
    }

    public function refundPayment(string $paymentId): Refund
    {
        /** @var Refund|null $refund */
        $refund = \Payplug\Refund::create($paymentId);
        Assert::isInstanceOf($refund, Refund::class);

        return $refund;
    }

    public function refundPaymentWithAmount(string $paymentId, int $amount): Refund
    {
        /** @var Refund|null $refund */
        $refund = \Payplug\Refund::create($paymentId, ['amount' => $amount]);
        Assert::isInstanceOf($refund, Refund::class);

        return $refund;
    }

    public function treat(string $input): IVerifiableAPIResource
    {
        return \Payplug\Notification::treat($input);
    }

    public function retrieve(string $paymentId): Payment
    {
        $payment = \Payplug\Payment::retrieve($paymentId);
        Assert::isInstanceOf($payment, Payment::class);

        return $payment;
    }

    public function getNotificationUrlDev(): ?string
    {
        return $this->notificationUrlDev;
    }
}
