<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
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

    public function findOneByPayPlugPaymentId(string $payplugPaymentId): PaymentInterface
    {
        return $this->createQueryBuilder('o')
            ->where('o.details LIKE :payplugPaymentId')
            ->setParameter('payplugPaymentId', '%'.$payplugPaymentId.'%')
            ->getQuery()
            ->setMaxResults(1)
            ->getSingleResult()
        ;
    }

    public function findAllAuthorizedOlderThanDays(int $days, ?string $gatewayFactoryName = null): array
    {
        if (null === $gatewayFactoryName) {
            // For now, only this gateway support authorized payments
            $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;
        }

        $date = (new \DateTime())->modify(sprintf('-%d days', $days));

        /** @var array<PaymentInterface>  */
        return $this->createQueryBuilder('o')
            ->innerJoin('o.method', 'method')
            ->innerJoin('method.gatewayConfig', 'gatewayConfig')
            ->where('o.state = :state')
            ->andWhere('o.updatedAt < :date')
            ->andWhere('gatewayConfig.factoryName = :factoryName')
            ->setParameter('state', PaymentInterface::STATE_AUTHORIZED)
            ->setParameter('factoryName', $gatewayFactoryName)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult()
        ;
    }
}
