<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\PayplugOperation;
use PayplugUnifiedCore\Contracts\IPaymentRepository;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\OperationData;

final class SyliusPaymentRepository implements IPaymentRepository
{
    public function __construct(
        private PayplugOperationRepositoryInterface $operationRepository,
    ) {
    }

    public function getByOrderId(string $orderId): OperationData
    {
        $operation = $this->operationRepository->findOneByOrderId($orderId);
        if (null === $operation) {
            throw new PaymentNotFoundException(sprintf('No operation for order "%s".', $orderId));
        }

        return $this->toOperationData($operation);
    }

    public function getByOperationId(string $operationId): OperationData
    {
        $operation = $this->operationRepository->findOneByOperationId($operationId);
        if (null === $operation) {
            throw new PaymentNotFoundException(sprintf('No operation for operation id "%s".', $operationId));
        }

        return $this->toOperationData($operation);
    }

    public function save(OperationData $operationData): void
    {
        $operation = $this->operationRepository->findOneByOperationId($operationData->operationId);

        if (null === $operation) {
            $operation = new PayplugOperation(
                $operationData->operationId,
                $operationData->orderId,
                $operationData->execCode,
                $operationData->outcome,
                $operationData->amount,
            );
            $this->operationRepository->add($operation);

            return;
        }

        $operation->setExecCode($operationData->execCode);
        $operation->setOutcome($operationData->outcome);
        $operation->setAmount($operationData->amount);
        $this->operationRepository->add($operation);
    }

    public function markTreated(string $operationId): void
    {
        $operation = $this->operationRepository->findOneByOperationId($operationId);
        if (null === $operation) {
            throw new PaymentNotFoundException(sprintf('No operation for operation id "%s".', $operationId));
        }
        $operation->markTreated();
        $this->operationRepository->add($operation);
    }

    public function isTreated(string $operationId): bool
    {
        $operation = $this->operationRepository->findOneByOperationId($operationId);
        if (null === $operation) {
            throw new PaymentNotFoundException(sprintf('No operation for operation id "%s".', $operationId));
        }

        return $operation->isTreated();
    }

    private function toOperationData(PayplugOperation $operation): OperationData
    {
        return new OperationData(
            $operation->getOperationId(),
            $operation->getExecCode(),
            $operation->getOutcome(),
            $operation->getAmount(),
            $operation->getOrderId(),
        );
    }
}
