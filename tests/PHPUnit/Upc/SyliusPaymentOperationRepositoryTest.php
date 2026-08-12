<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PayPlug\SyliusPayPlugPlugin\Entity\PayPlugOperation;
use PayPlug\SyliusPayPlugPlugin\Upc\SyliusPaymentOperationRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SyliusPaymentOperationRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $doctrineRepository;

    private SyliusPaymentOperationRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->doctrineRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->with(PayPlugOperation::class)->willReturn($this->doctrineRepository);
        $this->repository = new SyliusPaymentOperationRepository($this->entityManager);
    }

    public function testGetByOrderId_whenFound_returnsOperationData(): void
    {
        $entity = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);
        $this->doctrineRepository->method('findOneBy')->with(['orderId' => '42'])->willReturn($entity);

        $result = $this->repository->getByOrderId('42');

        self::assertSame('op_123', $result->operationId);
    }

    public function testGetByOrderId_whenMissing_throwsPaymentNotFoundException(): void
    {
        $this->doctrineRepository->method('findOneBy')->with(['orderId' => '42'])->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOrderId('42');
    }

    public function testGetByOperationId_whenFound_returnsOperationData(): void
    {
        $entity = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn($entity);

        $result = $this->repository->getByOperationId('op_123');

        self::assertSame('42', $result->orderId);
    }

    public function testGetByOperationId_whenMissing_throwsPaymentNotFoundException(): void
    {
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOperationId('op_123');
    }

    public function testSave_persistsAndFlushesANewEntity(): void
    {
        $data = new OperationData('op_123', '0000', PaymentOutcome::PAID, 1000, '42');
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist')
            ->with(self::isInstanceOf(PayPlugOperation::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->repository->save($data);
    }

    public function testIsTreated_delegatesToTheStoredEntity(): void
    {
        $entity = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);
        $entity->markTreated();
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn($entity);

        self::assertTrue($this->repository->isTreated('op_123'));
    }

    public function testIsTreated_whenMissing_returnsFalse(): void
    {
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn(null);

        self::assertFalse($this->repository->isTreated('op_123'));
    }

    public function testMarkTreated_flagsTheStoredEntityAndFlushes(): void
    {
        $entity = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);
        $this->doctrineRepository->method('findOneBy')->with(['operationId' => 'op_123'])->willReturn($entity);

        $this->entityManager->expects(self::once())->method('flush');

        $this->repository->markTreated('op_123');

        self::assertTrue($entity->isTreated());
    }
}
