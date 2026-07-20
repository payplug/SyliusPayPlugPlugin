<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Spike;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PayPlug\SyliusPayPlugPlugin\Spike\Entity\PayplugOperation;
use PayPlug\SyliusPayPlugPlugin\Spike\SyliusPaymentRepository;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\OperationData;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SyliusPaymentRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $objectRepository;

    private SyliusPaymentRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->objectRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(PayplugOperation::class)->willReturn($this->objectRepository);

        $this->repository = new SyliusPaymentRepository($this->entityManager);
    }

    public function testGetByOrderId_found_returnsMatchingOperationData(): void
    {
        $entity = PayplugOperation::fromOperationData($this->operationData());
        $this->objectRepository->method('findOneBy')->with(['orderId' => 'order-1'])->willReturn($entity);

        $result = $this->repository->getByOrderId('order-1');

        self::assertSame('op-1', $result->operationId);
        self::assertSame('order-1', $result->orderId);
    }

    public function testGetByOrderId_notFound_throwsPaymentNotFoundException(): void
    {
        $this->objectRepository->method('findOneBy')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOrderId('missing-order');
    }

    public function testGetByOperationId_found_returnsMatchingOperationData(): void
    {
        $entity = PayplugOperation::fromOperationData($this->operationData());
        $this->objectRepository->method('findOneBy')->with(['operationId' => 'op-1'])->willReturn($entity);

        $result = $this->repository->getByOperationId('op-1');

        self::assertSame('order-1', $result->orderId);
    }

    public function testGetByOperationId_notFound_throwsPaymentNotFoundException(): void
    {
        $this->objectRepository->method('findOneBy')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOperationId('missing-op');
    }

    public function testSave_newOperation_persistsAndFlushes(): void
    {
        $this->objectRepository->method('findOneBy')->with(['operationId' => 'op-1'])->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist')
            ->with(self::callback(fn (PayplugOperation $entity): bool => 'op-1' === $entity->getOperationId() && 'order-1' === $entity->getOrderId()));
        $this->entityManager->expects(self::once())->method('flush');

        $this->repository->save($this->operationData());
    }

    public function testSave_existingOperation_updatesInPlaceWithoutPersisting(): void
    {
        $existing = PayplugOperation::fromOperationData($this->operationData());
        $this->objectRepository->method('findOneBy')->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $updated = new OperationData('op-1', '4001', PaymentOutcome::REFUNDED, 500, 'order-1');
        $this->repository->save($updated);

        self::assertSame(PaymentOutcome::REFUNDED, $existing->toOperationData()->outcome);
        self::assertSame(500, $existing->toOperationData()->amount);
    }

    public function testMarkTreated_existing_marksAndFlushes(): void
    {
        $entity = PayplugOperation::fromOperationData($this->operationData());
        $this->objectRepository->method('findOneBy')->with(['operationId' => 'op-1'])->willReturn($entity);
        $this->entityManager->expects(self::once())->method('flush');

        $this->repository->markTreated('op-1');

        self::assertTrue($entity->isTreated());
    }

    public function testMarkTreated_missing_throwsPaymentNotFoundException(): void
    {
        $this->objectRepository->method('findOneBy')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->markTreated('missing-op');
    }

    public function testIsTreated_untreatedOperation_returnsFalse(): void
    {
        $entity = PayplugOperation::fromOperationData($this->operationData());
        $this->objectRepository->method('findOneBy')->willReturn($entity);

        self::assertFalse($this->repository->isTreated('op-1'));
    }

    public function testIsTreated_treatedOperation_returnsTrue(): void
    {
        $entity = PayplugOperation::fromOperationData($this->operationData());
        $entity->markTreated();
        $this->objectRepository->method('findOneBy')->willReturn($entity);

        self::assertTrue($this->repository->isTreated('op-1'));
    }

    /**
     * Idempotency checks must be tolerant of "never saved" rather than throwing — a webhook
     * asking "have I already treated this?" before any operation was ever persisted is a
     * legitimate call, not an error.
     */
    public function testIsTreated_unknownOperation_returnsFalse(): void
    {
        $this->objectRepository->method('findOneBy')->willReturn(null);

        self::assertFalse($this->repository->isTreated('unknown-op'));
    }

    private function operationData(): OperationData
    {
        return new OperationData('op-1', '4001', PaymentOutcome::PAID, 1000, 'order-1');
    }
}
