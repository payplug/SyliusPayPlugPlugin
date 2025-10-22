<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use Payplug\Payplug;
use Payplug\Resource\IVerifiableAPIResource;
use Payplug\Resource\Payment;
use Payplug\Resource\Refund;

interface PayPlugApiClientInterface
{
    public const INTERNAL_STATUS_ONE_CLICK = 'one_click';

    public const INTEGRATED_PAYMENT_INTEGRATION = 'INTEGRATED_PAYMENT';

    /** @deprecated */
    public const LIVE_KEY_PREFIX = 'sk_live';

    /** @deprecated */
    public const TEST_KEY_PREFIX = 'sk_test';

    public const STATUS_CREATED = 'created';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_CANCELED_BY_ONEY = 'canceled_by_oney';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_AUTHORIZED = 'authorized';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    public function getConfiguration(): Payplug;

    public function getAccount(bool $refresh = false): array;

    public function getGatewayFactoryName(): string;

    public function getPermissions(): array;

    public function createPayment(array $data): Payment;

    public function abortPayment(string $paymentId): Payment;

    public function refundPayment(string $paymentId): Refund;

    public function refundPaymentWithAmount(string $paymentId, int $amount, int $refundId): Refund;

    public function treat(string $input): IVerifiableAPIResource;

    public function retrieve(string $paymentId): Payment;

    public function deleteCard(string $card): void;
}
