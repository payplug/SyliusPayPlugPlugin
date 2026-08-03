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
        self::assertSame('hostedFieldsCompanyId', PayPlugGatewayFactory::HOSTED_FIELDS_COMPANY_ID);
    }
}
