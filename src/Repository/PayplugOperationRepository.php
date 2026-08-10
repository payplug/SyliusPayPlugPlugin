<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\PayplugOperation;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

final class PayplugOperationRepository extends EntityRepository implements PayplugOperationRepositoryInterface
{
    public function findOneByOperationId(string $operationId): ?PayplugOperation
    {
        // @phpstan-ignore-next-line return.type (getOneOrNullResult() is typed mixed by Doctrine)
        return $this->createQueryBuilder('operation')
            ->where('operation.operationId = :operationId')
            ->setParameter('operationId', $operationId)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function findOneByOrderId(string $orderId): ?PayplugOperation
    {
        // @phpstan-ignore-next-line return.type (getOneOrNullResult() is typed mixed by Doctrine)
        return $this->createQueryBuilder('operation')
            ->where('operation.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('operation.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
