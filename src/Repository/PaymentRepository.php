<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Sylius\Bundle\CoreBundle\Doctrine\ORM\PaymentRepository as BasePaymentRepository;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentRepository extends BasePaymentRepository implements PaymentRepositoryInterface
{
    public function findAllActiveByGatewayFactoryName(string $gatewayFactoryName): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.method', 'method')
            ->innerJoin('method.gatewayConfig', 'gatewayConfig')
            ->where('gatewayConfig.factoryName = :gatewayFactoryName')
            ->andWhere('o.state = :stateNew OR o.state = :stateProcessing')
            ->setParameter('gatewayFactoryName', $gatewayFactoryName)
            ->setParameter('stateNew', PaymentInterface::STATE_NEW)
            ->setParameter('stateProcessing', PaymentInterface::STATE_PROCESSING)
            ->getQuery()
            ->getResult()
        ;
    }
}
