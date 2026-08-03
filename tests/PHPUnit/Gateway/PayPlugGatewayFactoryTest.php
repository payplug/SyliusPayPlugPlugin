<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\TestCase;

final class PayPlugGatewayFactoryTest extends TestCase
{
    public function testHostedFieldsConfigKeysAreStable(): void
    {
        self::assertSame('hostedFields', PayPlugGatewayFactory::HOSTED_FIELDS);
        self::assertSame('hostedFieldsKeyId', PayPlugGatewayFactory::HOSTED_FIELDS_KEY_ID);
        self::assertSame('hostedFieldsKeyValue', PayPlugGatewayFactory::HOSTED_FIELDS_KEY_VALUE);
    }
}
