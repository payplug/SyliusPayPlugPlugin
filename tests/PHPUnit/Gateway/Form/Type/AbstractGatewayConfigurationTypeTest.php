<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the hooks `AbstractGatewayConfigurationType` exposes to its per-gateway subtypes.
 *
 * The per-channel uniqueness rule they used to sit next to now lives in
 * `GatewayChannelConflictChecker` and `PaymentMethodTypeExtension`.
 */
final class AbstractGatewayConfigurationTypeTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private AbstractGatewayConfigurationType $type;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $this->type = new AbstractGatewayConfigurationType(
            $this->translator,
        );
    }

    /**
     * Default hook implementation: every gateway subtype that doesn't override it keeps
     * today's behavior of always enforcing the base currency.
     */
    public function testShouldValidateBaseCurrency_defaultImplementation_alwaysReturnsTrue(): void
    {
        self::assertTrue($this->type->shouldValidateBaseCurrency([]));
        self::assertTrue($this->type->shouldValidateBaseCurrency(['anything' => 'irrelevant']));
    }

    /**
     * Default hook implementation: every gateway subtype that doesn't override it keeps today's
     * generic per-gateway wording (only `PayPlugGatewayConfigurationType` overrides this, for a
     * message specific to Integrated Payment).
     */
    public function testBaseCurrencyViolationMessage_defaultImplementation_returnsGenericKey(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('channel_code');

        self::assertSame(
            'payplug_sylius_payplug_plugin.form.base_currency_not_euro',
            $this->type->baseCurrencyViolationMessage($channel),
        );
    }

    public function testGetBaseCurrencyCode_defaultImplementation_isEuro(): void
    {
        self::assertSame('EUR', $this->type->getBaseCurrencyCode());
    }

    /**
     * The only subtype that narrows the hook: Integrated Payment is the only display mode that
     * requires every associated channel to be EUR.
     */
    public function testShouldValidateBaseCurrency_payPlugType_onlyAppliesToIntegratedPayment(): void
    {
        $type = new PayPlugGatewayConfigurationType(
            $this->translator,
        );

        self::assertTrue($type->shouldValidateBaseCurrency([
            PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
        ]));
        self::assertFalse($type->shouldValidateBaseCurrency([
            PayPlugGatewayFactory::DISPLAY_MODE_FIELD => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
        ]));
        self::assertFalse($type->shouldValidateBaseCurrency([]));
    }
}
