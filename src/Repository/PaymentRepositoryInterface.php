<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface as BasePaymentRepositoryInterface;

interface PaymentRepositoryInterface extends BasePaymentRepositoryInterface
{
    public function findAllActiveByGatewayFactoryName(string $gatewayFactoryName): array;

    public function findOneByPayPlugPaymentId(string $payplugPaymentId): ?PaymentInterface;

    /**
     * Payments of the given order and amount whose Unified API creation has committed but that
     * have no hosted_fields_payment_id yet. At most two are returned: callers only need to know
     * whether exactly one matches.
     *
     * @return array<PaymentInterface>
     */
    public function findAwaitingHostedFieldsPaymentId(string $orderNumber, int $amount): array;

    /**
     * @return array<PaymentInterface>
     */
    public function findAllAuthorizedOlderThanDays(int $days, ?string $gatewayFactoryName = null): array;
}
