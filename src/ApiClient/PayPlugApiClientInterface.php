<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;

interface PayPlugApiClientInterface
{
    public const STATUS_CREATED = 'created';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_CAPTURED = 'captured';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    /**
     * @deprecated
     */
    public function initialise(string $secretKey): void;

    public function getAccount(): array;

    public function getPermissions(): array;

    public function createPayment(array $data): Payment;

    public function refundPayment(string $paymentId): Refund;

    public function refundPaymentWithAmount(string $paymentId, int $amount, int $refundId): Refund;

    public function treat(string $input): IVerifiableAPIResource;

    public function retrieve(string $paymentId): Payment;
}
