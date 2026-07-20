<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Spike;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Spike\Entity\PayplugOperation;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\OperationData;

/**
 * PRE-3469 spike: proof-of-concept implementation of IPaymentRepository against a Doctrine
 * entity — not shipped code. See PayplugOperation for the schema friction this surfaced.
 */
final class SyliusPaymentRepository implements IPaymentRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getByOrderId(string $orderId): OperationData
    {
        return $this->requireOperation(['orderId' => $orderId], \sprintf('No operation for order "%s".', $orderId))->toOperationData();
    }

    public function getByOperationId(string $operationId): OperationData
    {
        return $this->requireOperation(['operationId' => $operationId], \sprintf('No operation "%s".', $operationId))->toOperationData();
    }

    public function save(OperationData $operationData): void
    {
        $existing = $this->findOperation(['operationId' => $operationData->operationId]);

        if (null === $existing) {
            $this->entityManager->persist(PayplugOperation::fromOperationData($operationData));
        } else {
            $existing->updateFromOperationData($operationData);
        }

        $this->entityManager->flush();
    }

    public function markTreated(string $operationId): void
    {
        $operation = $this->requireOperation(['operationId' => $operationId], \sprintf('No operation "%s".', $operationId));
        $operation->markTreated();
        $this->entityManager->flush();
    }

    public function isTreated(string $operationId): bool
    {
        $operation = $this->findOperation(['operationId' => $operationId]);

        return null !== $operation && $operation->isTreated();
    }

    /**
     * @param array<string, string> $criteria
     */
    private function requireOperation(array $criteria, string $notFoundMessage): PayplugOperation
    {
        $operation = $this->findOperation($criteria);
        if (null === $operation) {
            throw new PaymentNotFoundException($notFoundMessage);
        }

        return $operation;
    }

    /**
     * @param array<string, string> $criteria
     */
    private function findOperation(array $criteria): ?PayplugOperation
    {
        return $this->entityManager->getRepository(PayplugOperation::class)->findOneBy($criteria);
    }
}
