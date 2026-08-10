<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\PayplugOperation;
use PayPlug\SyliusPayPlugPlugin\Repository\PayplugOperationRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\SyliusPaymentRepository;
use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SyliusPaymentRepositoryTest extends TestCase
{
    private PayplugOperationRepositoryInterface&MockObject $operationRepository;

    private SyliusPaymentRepository $repository;

    protected function setUp(): void
    {
        $this->operationRepository = $this->createMock(PayplugOperationRepositoryInterface::class);
        $this->repository = new SyliusPaymentRepository($this->operationRepository);
    }

    // -------------------------------------------------------------------------
    // getByOrderId() / getByOperationId()
    // -------------------------------------------------------------------------

    public function testGetByOrderId_whenFound_returnsOperationData(): void
    {
        $operation = new PayplugOperation('op_1', 'order_42', '0000', PaymentOutcome::PAID, 1000);
        $this->operationRepository->method('findOneByOrderId')->with('order_42')->willReturn($operation);

        $result = $this->repository->getByOrderId('order_42');

        self::assertSame('op_1', $result->operationId);
        self::assertSame('order_42', $result->orderId);
        self::assertSame(PaymentOutcome::PAID, $result->outcome);
        self::assertSame(1000, $result->amount);
    }

    public function testGetByOrderId_whenNotFound_throwsPaymentNotFoundException(): void
    {
        $this->operationRepository->method('findOneByOrderId')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOrderId('unknown_order');
    }

    public function testGetByOperationId_whenNotFound_throwsPaymentNotFoundException(): void
    {
        $this->operationRepository->method('findOneByOperationId')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->getByOperationId('unknown_op');
    }

    // -------------------------------------------------------------------------
    // save() — insert vs update
    // -------------------------------------------------------------------------

    public function testSave_whenOperationDoesNotExistYet_addsANewEntity(): void
    {
        $this->operationRepository->method('findOneByOperationId')->with('op_1')->willReturn(null);

        $this->operationRepository->expects(self::once())
            ->method('add')
            ->with(self::callback(
                static fn (PayplugOperation $operation): bool => 'op_1' === $operation->getOperationId() &&
                    'order_42' === $operation->getOrderId() &&
                    PaymentOutcome::PAID === $operation->getOutcome(),
            ))
        ;

        $this->repository->save(new OperationData('op_1', '0000', PaymentOutcome::PAID, 1000, 'order_42'));
    }

    public function testSave_whenOperationAlreadyExists_updatesItInPlace(): void
    {
        $existing = new PayplugOperation('op_1', 'order_42', '0001', PaymentOutcome::FAILED, 1000);
        $this->operationRepository->method('findOneByOperationId')->with('op_1')->willReturn($existing);

        $this->operationRepository->expects(self::once())->method('add')->with($existing);

        $this->repository->save(new OperationData('op_1', '0000', PaymentOutcome::PAID, 1000, 'order_42'));

        self::assertSame(PaymentOutcome::PAID, $existing->getOutcome());
        self::assertSame('0000', $existing->getExecCode());
    }

    // -------------------------------------------------------------------------
    // markTreated() / isTreated()
    // -------------------------------------------------------------------------

    public function testMarkTreated_setsTheFlagAndPersists(): void
    {
        $operation = new PayplugOperation('op_1', 'order_42', '0000', PaymentOutcome::PAID, 1000);
        $this->operationRepository->method('findOneByOperationId')->with('op_1')->willReturn($operation);
        $this->operationRepository->expects(self::once())->method('add')->with($operation);

        $this->repository->markTreated('op_1');

        self::assertTrue($operation->isTreated());
    }

    public function testMarkTreated_whenNotFound_throwsPaymentNotFoundException(): void
    {
        $this->operationRepository->method('findOneByOperationId')->willReturn(null);

        $this->expectException(PaymentNotFoundException::class);

        $this->repository->markTreated('unknown_op');
    }

    public function testIsTreated_reflectsTheStoredFlag(): void
    {
        $operation = new PayplugOperation('op_1', 'order_42', '0000', PaymentOutcome::PAID, 1000);
        $operation->markTreated();
        $this->operationRepository->method('findOneByOperationId')->with('op_1')->willReturn($operation);

        self::assertTrue($this->repository->isTreated('op_1'));
    }
}
