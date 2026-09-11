<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentMethodRepository as BasePaymentMethodRepository;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class PaymentMethodRepository extends BasePaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function findOneByGatewayName(string $gatewayFactoryName): ?PaymentMethodInterface
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.gatewayConfig', 'gatewayConfig')
            ->where('gatewayConfig.factoryName = :gatewayFactoryName')
            ->setParameter('gatewayFactoryName', $gatewayFactoryName)
            ->getQuery()
            ->setMaxResults(1)
            ->getSingleResult()
        ;
    }

    public function findEnabledByGatewayName(string $gatewayFactoryName): array
    {
        /** @var array<PaymentMethodInterface> $paymentMethods */
        $paymentMethods = $this->createQueryBuilder('o')
            ->innerJoin('o.gatewayConfig', 'gatewayConfig')
            ->leftJoin('o.channels', 'channel')
            ->addSelect('channel')
            ->andWhere('gatewayConfig.factoryName = :gatewayFactoryName')
            ->andWhere('o.enabled = true')
            ->setParameter('gatewayFactoryName', $gatewayFactoryName)
            ->getQuery()
            ->getResult()
        ;

        return $paymentMethods;
    }
}
