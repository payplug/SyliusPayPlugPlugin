<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\TestCase;

final class PayPlugGatewayFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // resolveDisplayMode()
    // -------------------------------------------------------------------------

    public function testResolveDisplayMode_hostedFieldsTrue_returnsHostedFields(): void
    {
        self::assertSame(
            PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            PayPlugGatewayFactory::resolveDisplayMode([PayPlugGatewayFactory::HOSTED_FIELDS => true]),
        );
    }

    public function testResolveDisplayMode_hostedFieldsTrueAndIntegratedPaymentTrue_hostedFieldsWins(): void
    {
        self::assertSame(
            PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            PayPlugGatewayFactory::resolveDisplayMode([
                PayPlugGatewayFactory::HOSTED_FIELDS => true,
                PayPlugGatewayFactory::INTEGRATED_PAYMENT => true,
            ]),
        );
    }

    public function testResolveDisplayMode_integratedPaymentTrue_returnsIntegratedPayment(): void
    {
        self::assertSame(
            PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
            PayPlugGatewayFactory::resolveDisplayMode([PayPlugGatewayFactory::INTEGRATED_PAYMENT => true]),
        );
    }

    public function testResolveDisplayMode_neitherFlagSet_returnsNull(): void
    {
        self::assertNull(PayPlugGatewayFactory::resolveDisplayMode([]));
    }

    public function testResolveDisplayMode_bothFlagsFalse_returnsNull(): void
    {
        self::assertNull(PayPlugGatewayFactory::resolveDisplayMode([
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
        ]));
    }

    // -------------------------------------------------------------------------
    // resolveDisplayModeFlags()
    // -------------------------------------------------------------------------

    public function testResolveDisplayModeFlags_hostedFields_setsHostedFieldsOnlyTrue(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::INTEGRATED_PAYMENT => false, PayPlugGatewayFactory::HOSTED_FIELDS => true],
            PayPlugGatewayFactory::resolveDisplayModeFlags(PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS),
        );
    }

    public function testResolveDisplayModeFlags_integratedPayment_setsIntegratedPaymentOnlyTrue(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::INTEGRATED_PAYMENT => true, PayPlugGatewayFactory::HOSTED_FIELDS => false],
            PayPlugGatewayFactory::resolveDisplayModeFlags(PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT),
        );
    }

    public function testResolveDisplayModeFlags_null_setsBothFalse(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::INTEGRATED_PAYMENT => false, PayPlugGatewayFactory::HOSTED_FIELDS => false],
            PayPlugGatewayFactory::resolveDisplayModeFlags(null),
        );
    }

    public function testResolveDisplayModeFlags_unknownValue_setsBothFalse(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::INTEGRATED_PAYMENT => false, PayPlugGatewayFactory::HOSTED_FIELDS => false],
            PayPlugGatewayFactory::resolveDisplayModeFlags('not_a_real_mode'),
        );
    }

    // -------------------------------------------------------------------------
    // missingHostedFieldsRequirements()
    // -------------------------------------------------------------------------

    public function testMissingHostedFieldsRequirements_hostedFieldsNotSelected_returnsEmpty(): void
    {
        self::assertSame([], PayPlugGatewayFactory::missingHostedFieldsRequirements([
            PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
        ]));
    }

    public function testMissingHostedFieldsRequirements_hostedFieldsSelectedBothFieldsMissing_returnsBoth(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::HF_IDENTIFIER, PayPlugGatewayFactory::HF_SUB_MERCHANT_ID],
            PayPlugGatewayFactory::missingHostedFieldsRequirements([
                PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            ]),
        );
    }

    public function testMissingHostedFieldsRequirements_hostedFieldsSelectedOnlyIdentifierBlank_returnsIdentifierOnly(): void
    {
        self::assertSame(
            [PayPlugGatewayFactory::HF_IDENTIFIER],
            PayPlugGatewayFactory::missingHostedFieldsRequirements([
                PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
                PayPlugGatewayFactory::HF_IDENTIFIER => '   ',
                PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sm_123',
            ]),
        );
    }

    public function testMissingHostedFieldsRequirements_hostedFieldsSelectedBothFieldsFilled_returnsEmpty(): void
    {
        self::assertSame([], PayPlugGatewayFactory::missingHostedFieldsRequirements([
            PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'sm_123',
        ]));
    }
}
