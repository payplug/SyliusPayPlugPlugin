<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Entity;

use PayPlug\SyliusPayPlugPlugin\Entity\PayPlugOperation;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PHPUnit\Framework\TestCase;

final class PayPlugOperationTest extends TestCase
{
    public function testConstruct_setsAllFieldsAndDefaultsTreatedToFalse(): void
    {
        $operation = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);

        self::assertSame('42', $operation->getOrderId());
        self::assertSame('op_123', $operation->getOperationId());
        self::assertSame('0000', $operation->getExecCode());
        self::assertSame(PaymentOutcome::PAID, $operation->getOutcome());
        self::assertSame(1000, $operation->getAmount());
        self::assertFalse($operation->isTreated());
    }

    public function testMarkTreated_setsTreatedToTrue(): void
    {
        $operation = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);

        $operation->markTreated();

        self::assertTrue($operation->isTreated());
    }

    public function testToOperationData_returnsEquivalentValueObject(): void
    {
        $operation = new PayPlugOperation('42', 'op_123', '0000', PaymentOutcome::PAID, 1000);

        $data = $operation->toOperationData();

        self::assertSame('op_123', $data->operationId);
        self::assertSame('0000', $data->execCode);
        self::assertSame(PaymentOutcome::PAID, $data->outcome);
        self::assertSame(1000, $data->amount);
        self::assertSame('42', $data->orderId);
    }
}
