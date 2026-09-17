<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface as BasePaymentMethodRepositoryInterface;

interface PaymentMethodRepositoryInterface extends BasePaymentMethodRepositoryInterface
{
    /**
     * @deprecated since PRE-3440. Several gateway configs may share a factory name — one per
     *             channel — so this returns an arbitrary one of them. Use
     *             {@see self::findOneEnabledByGatewayNameAndChannel()} wherever a channel is in
     *             scope.
     */
    public function findOneByGatewayName(string $gatewayFactoryName): ?PaymentMethodInterface;

    /**
     * The enabled payment method of that factory serving $channel, or null if the channel has none.
     *
     * This is the channel-safe replacement for {@see self::findOneByGatewayName()}: since PRE-3628
     * a merchant may run several configs of one factory, each connected to a different PayPlug
     * account, and a name-only lookup picks between them arbitrarily.
     */
    public function findOneEnabledByGatewayNameAndChannel(
        string $gatewayFactoryName,
        ChannelInterface $channel,
    ): ?PaymentMethodInterface;

    /**
     * @return list<PaymentMethodInterface>
     */
    public function findEnabledByGatewayName(string $gatewayFactoryName): array;
}
