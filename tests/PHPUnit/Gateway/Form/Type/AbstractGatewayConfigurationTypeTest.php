<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
            $this->createMock(RequestStack::class),
        );
    }

    /**
     * Default hook implementation: every gateway subtype that doesn't override it keeps
     * today's behavior of always enforcing the base currency.
     */
    public function testShouldValidateBaseCurrency_defaultImplementation_alwaysReturnsTrue(): void
    {
        self::assertTrue($this->shouldValidateBaseCurrency([]));
        self::assertTrue($this->shouldValidateBaseCurrency(['anything' => 'irrelevant']));
    }

    private function shouldValidateBaseCurrency(array $data): bool
    {
        $method = new \ReflectionMethod(AbstractGatewayConfigurationType::class, 'shouldValidateBaseCurrency');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($this->type, $data);

        return $result;
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
            $this->baseCurrencyViolationMessage($channel),
        );
    }

    private function baseCurrencyViolationMessage(ChannelInterface $channel): string
    {
        $method = new \ReflectionMethod(AbstractGatewayConfigurationType::class, 'baseCurrencyViolationMessage');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($this->type, $channel);

        return $result;
    }
}
