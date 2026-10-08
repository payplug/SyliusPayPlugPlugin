<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\HostedFieldsPaymentIdExtractor;
use PHPUnit\Framework\TestCase;

final class HostedFieldsPaymentIdExtractorTest extends TestCase
{
    public function testFromWebhook_onAPaymentOperation_returnsTheTopLevelId(): void
    {
        self::assertSame('pay_1', HostedFieldsPaymentIdExtractor::fromWebhook([
            'operationType' => 'PAYMENT',
            'id' => 'pay_1',
            'execCode' => '0000',
        ]));
    }

    public function testFromWebhook_ignoresIdsNestedInPaymentMethod(): void
    {
        self::assertNull(HostedFieldsPaymentIdExtractor::fromWebhook([
            'operationType' => 'PAYMENT',
            'paymentMethod' => ['id' => 'card_1', 'storedId' => 'card_1'],
        ]));
    }

    /** @dataProvider withoutAUsableId */
    public function testFromWebhook_withoutAUsableId_returnsNull(mixed $decoded): void
    {
        self::assertNull(HostedFieldsPaymentIdExtractor::fromWebhook($decoded));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function withoutAUsableId(): iterable
    {
        yield 'no id key' => [['operationType' => 'PAYMENT', 'execCode' => '0000']];
        yield 'empty id' => [['operationType' => 'PAYMENT', 'id' => '']];
        yield 'non string id' => [['operationType' => 'PAYMENT', 'id' => 123]];
        yield 'array id' => [['operationType' => 'PAYMENT', 'id' => ['pay_1']]];
        yield 'refund operation' => [['operationType' => 'REFUND', 'id' => 'op_refund_1']];
        yield 'no operation type' => [['id' => 'pay_1']];
        yield 'lowercase operation type' => [['operationType' => 'payment', 'id' => 'pay_1']];
        yield 'non string operation type' => [['operationType' => ['PAYMENT'], 'id' => 'pay_1']];
        yield 'malformed json decoded to null' => [null];
        yield 'scalar' => ['pay_1'];
    }
}
