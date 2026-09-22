<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PayPlugGatewayConfigurationTypeTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private PayPlugGatewayConfigurationType $type;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $this->type = new PayPlugGatewayConfigurationType(
            $this->translator,
        );
    }

    /**
     * The arrays below are keyed by the persisted `integratedPayment`/`hostedFields` booleans
     * written by `PayPlugGatewayFactory::resolveDisplayModeFlags()`, i.e. the shape the production
     * caller hands the hook (`GatewayConfigInterface::getConfig()`). The `hostedFieldsMode` form
     * field is unmapped and never reaches the persisted config.
     */
    public function testShouldValidateBaseCurrency_integratedPaymentSelected_returnsTrue(): void
    {
        self::assertTrue($this->shouldValidateBaseCurrency([
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => true,
        ]));
    }

    public function testShouldValidateBaseCurrency_hostedFieldsSelected_returnsFalse(): void
    {
        self::assertFalse($this->shouldValidateBaseCurrency([
            PayPlugGatewayFactory::HOSTED_FIELDS => true,
        ]));
    }

    public function testShouldValidateBaseCurrency_noModeSelected_returnsFalse(): void
    {
        self::assertFalse($this->shouldValidateBaseCurrency([]));
    }

    /**
     * PRE-3553: this must be a message specific to Integrated Payment, not the generic
     * `base_currency_not_euro` wording used by every other gateway subtype - since
     * shouldValidateBaseCurrency() above only lets this fire when integrated_payment is
     * selected, it doesn't need to branch on mode itself.
     */
    public function testBaseCurrencyViolationMessage_returnsIntegratedPaymentSpecificKey(): void
    {
        $channel = $this->createMock(ChannelInterface::class);

        self::assertSame(
            'payplug_sylius_payplug_plugin.form.integrated_payment_currency_incompatible',
            $this->baseCurrencyViolationMessage($channel),
        );
    }

    private function shouldValidateBaseCurrency(array $data): bool
    {
        $method = new \ReflectionMethod(PayPlugGatewayConfigurationType::class, 'shouldValidateBaseCurrency');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($this->type, $data);

        return $result;
    }

    private function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        $method = new \ReflectionMethod(PayPlugGatewayConfigurationType::class, 'baseCurrencyViolationMessage');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($this->type, $channel);

        return $result;
    }
}
