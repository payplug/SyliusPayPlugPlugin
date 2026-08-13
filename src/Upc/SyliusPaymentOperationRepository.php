<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\PayPlugOperation;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;

final class SyliusPaymentOperationRepository implements IPaymentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getByOrderId(string $orderId): OperationData
    {
        $entity = $this->findOneBy(['orderId' => $orderId]);
        if (null === $entity) {
            throw new PaymentNotFoundException(\sprintf('No operation for order "%s".', $orderId));
        }

        return $entity->toOperationData();
    }

    public function getByOperationId(string $operationId): OperationData
    {
        $entity = $this->findOneByOperationId($operationId);
        if (null === $entity) {
            throw new PaymentNotFoundException(\sprintf('No operation "%s".', $operationId));
        }

        return $entity->toOperationData();
    }

    public function save(OperationData $operationData): void
    {
        $entity = $this->findOneByOperationId($operationData->operationId);
        if (null === $entity) {
            $entity = new PayPlugOperation(
                $operationData->orderId,
                $operationData->operationId,
                $operationData->execCode,
                $operationData->outcome,
                $operationData->amount,
            );
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function markTreated(string $operationId): void
    {
        $entity = $this->findOneByOperationId($operationId);
        if (null === $entity) {
            throw new PaymentNotFoundException(\sprintf('No operation "%s".', $operationId));
        }

        $entity->markTreated();
        $this->entityManager->flush();
    }

    public function isTreated(string $operationId): bool
    {
        $entity = $this->findOneByOperationId($operationId);

        return null !== $entity && $entity->isTreated();
    }

    /**
     * @param array<string, string> $criteria
     */
    private function findOneBy(array $criteria): ?PayPlugOperation
    {
        return $this->entityManager->getRepository(PayPlugOperation::class)->findOneBy($criteria);
    }

    private function findOneByOperationId(string $operationId): ?PayPlugOperation
    {
        return $this->findOneBy(['operationId' => $operationId]);
    }
}
