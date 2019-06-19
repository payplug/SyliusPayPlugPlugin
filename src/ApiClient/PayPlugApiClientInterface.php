<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Resource\Payment;
use Payplug\Resource\Refund;

interface PayPlugApiClientInterface
{
    public const STATUS_CREATED = 'created';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_CAPTURED = 'captured';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';

    public function initialise(string $secretKey, ?string $notificationUrlDev = null): void;

    public function createPayment(array $data): Payment;

    public function refundPayment(string $paymentId): Refund;

    public function treat($input);

    public function retrieve(string $paymentId): Payment;

    public function getNotificationUrlDev(): ?string;
}
