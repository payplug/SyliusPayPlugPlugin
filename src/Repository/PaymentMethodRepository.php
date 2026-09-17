<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentMethodRepository as BasePaymentMethodRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Webmozart\Assert\Assert;

final class PaymentMethodRepository extends BasePaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function findOneByGatewayName(string $gatewayFactoryName): ?PaymentMethodInterface
    {
        $paymentMethod = $this->createGatewayFactoryQueryBuilder($gatewayFactoryName)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult()
        ;

        Assert::nullOrIsInstanceOf($paymentMethod, PaymentMethodInterface::class);

        return $paymentMethod;
    }

    public function findOneEnabledByGatewayNameAndChannel(
        string $gatewayFactoryName,
        ChannelInterface $channel,
    ): ?PaymentMethodInterface {
        $paymentMethod = $this->createGatewayFactoryQueryBuilder($gatewayFactoryName)
            ->innerJoin('o.channels', 'channel')
            ->andWhere('channel = :channel')
            ->andWhere('o.enabled = true')
            ->setParameter('channel', $channel)
            // PRE-3628 admits at most one enabled config per channel per factory, so this orders a
            // set of one. It only matters if some non-form write path ever breaks that invariant,
            // where picking deterministically beats picking arbitrarily.
            ->addOrderBy('o.position', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult()
        ;

        Assert::nullOrIsInstanceOf($paymentMethod, PaymentMethodInterface::class);

        return $paymentMethod;
    }

    public function findEnabledByGatewayName(string $gatewayFactoryName): array
    {
        $paymentMethods = $this->createGatewayFactoryQueryBuilder($gatewayFactoryName)
            ->leftJoin('o.channels', 'channel')
            ->addSelect('channel')
            ->andWhere('o.enabled = true')
            ->getQuery()
            ->getResult()
        ;

        Assert::isList($paymentMethods);
        Assert::allIsInstanceOf($paymentMethods, PaymentMethodInterface::class);

        return $paymentMethods;
    }

    /**
     * The shared head of every lookup here: payment methods whose gateway config names $factoryName.
     *
     * Each caller narrows it further — by channel, by enabled, or not at all — so the join, the
     * predicate and the bound parameter live in one place rather than being repeated three times.
     */
    private function createGatewayFactoryQueryBuilder(string $gatewayFactoryName): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.gatewayConfig', 'gatewayConfig')
            ->andWhere('gatewayConfig.factoryName = :gatewayFactoryName')
            ->setParameter('gatewayFactoryName', $gatewayFactoryName)
        ;
    }
}
