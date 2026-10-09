<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PHPUnit\Framework\TestCase;

/**
 * The Unified API reads the hosted-fields token from paymentMethod.hfToken and the saved-card alias
 * from paymentMethod.storedId, and refuses a payment creation without them.
 */
final class UnifiedApiContractTest extends TestCase
{
    public function testHostedFieldDto_sendsTheTokenInPaymentMethodHfToken(): void
    {
        $dto = new HostedFieldDto(
            new CommonFieldsDto('acct_123', 1000, 'EUR', '000000042'),
            'hf_token_abc',
            paymentMethod: ['details' => ['fullName' => 'John Doe'], 'saveFutureUsage' => true],
        );

        $body = $dto->createPayloadBody();

        self::assertSame('hf_token_abc', $body['paymentMethod']['hfToken'] ?? null);
        self::assertSame('John Doe', $body['paymentMethod']['details']['fullName'] ?? null);
        self::assertTrue($body['paymentMethod']['saveFutureUsage'] ?? false);
    }

    public function testPaymentDto_sendsTheAliasInPaymentMethodStoredId(): void
    {
        $dto = new PaymentDto(
            new CommonFieldsDto('acct_123', 1000, 'EUR', '000000042'),
            'card_alias_1',
            'ONE_CLICK',
        );

        $body = $dto->createPayloadBody();

        self::assertSame('card_alias_1', $body['paymentMethod']['storedId'] ?? null);
    }
}
