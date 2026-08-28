<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\RefundDetailsLockKey;
use PHPUnit\Framework\TestCase;

final class RefundDetailsLockKeyTest extends TestCase
{
    public function testForPaymentId_withAnIntId_returnsThePrefixedKey(): void
    {
        self::assertSame('payplug_upc_refund_details_42', RefundDetailsLockKey::forPaymentId(42));
    }

    public function testForPaymentId_withAStringId_returnsThePrefixedKey(): void
    {
        self::assertSame('payplug_upc_refund_details_42', RefundDetailsLockKey::forPaymentId('42'));
    }

    public function testForPaymentId_withANonScalarId_throws(): void
    {
        $this->expectException(\LogicException::class);

        RefundDetailsLockKey::forPaymentId([42]);
    }

    /**
     * The same payment id must always resolve to the same key regardless of caller — this is what
     * lets RefundPaymentProcessor and HostedFieldsWebhookNotificationHandler actually serialize
     * against each other.
     */
    public function testForPaymentId_isStableAcrossCalls(): void
    {
        self::assertSame(RefundDetailsLockKey::forPaymentId(42), RefundDetailsLockKey::forPaymentId(42));
    }
}
