<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface as BasePaymentRepositoryInterface;

interface PaymentRepositoryInterface extends BasePaymentRepositoryInterface
{
    public function findAllActiveByGatewayFactoryName(string $gatewayFactoryName): array;

    public function findOneByPayPlugPaymentId(string $payplugPaymentId): PaymentInterface;

    /**
     * @return array<PaymentInterface>
     */
    public function findAllAuthorizedOlderThanDays(int $days, ?string $gatewayFactoryName = null): array;
}
