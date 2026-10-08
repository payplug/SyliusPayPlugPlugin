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

    public function findOneByPayPlugPaymentId(string $payplugPaymentId): ?PaymentInterface
    {
        /** @var PaymentInterface|null $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.details LIKE :payplugPaymentId')
            ->setParameter('payplugPaymentId', '%' . $payplugPaymentId . '%')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult()
        ;

        return $result;
    }

    public function findAwaitingHostedFieldsPaymentId(string $orderNumber, int $amount): array
    {
        /** @var array<PaymentInterface> $result */
        $result = $this->createQueryBuilder('o')
            ->innerJoin('o.order', 'ord')
            ->where('ord.number = :orderNumber')
            ->andWhere('o.amount = :amount')
            ->andWhere('o.state IN (:activeStates)')
            ->andWhere("(o.details LIKE :hostedFieldsCreatedAt ESCAPE '!' OR o.details LIKE :aliasPaymentCreatedAt ESCAPE '!')")
            ->andWhere("o.details NOT LIKE :paymentId ESCAPE '!'")
            ->setParameter('orderNumber', $orderNumber)
            ->setParameter('amount', $amount)
            ->setParameter('activeStates', [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING])
            ->setParameter('hostedFieldsCreatedAt', self::containsJsonKey('hosted_fields_created_at'))
            ->setParameter('aliasPaymentCreatedAt', self::containsJsonKey('alias_payment_created_at'))
            ->setParameter('paymentId', self::containsJsonKey('hosted_fields_payment_id'))
            ->setMaxResults(2)
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    /** A LIKE pattern, escaped with "!", matching a JSON document that contains $key as a key. */
    private static function containsJsonKey(string $key): string
    {
        return '%"' . \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $key) . '"%';
    }

    /**
     * @return array<PaymentInterface>
     */
    public function findAllAuthorizedOlderThanDays(int $days, ?string $gatewayFactoryName = null): array
    {
        if (null === $gatewayFactoryName) {
            // For now, only this gateway support authorized payments
            $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;
        }

        $date = (new \DateTime())->modify(sprintf('-%d days', $days));

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
