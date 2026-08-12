<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the one-payment-method-per-gateway-factory rule enforced by canBeCreated().
 *
 * canBeCreated() is private and only reachable through the form PRE_SUBMIT listener, which would
 * require mocking a full three-level form tree; it is therefore invoked here via reflection.
 */
final class AbstractGatewayConfigurationTypeTest extends TestCase
{
    private RepositoryInterface&MockObject $gatewayConfigRepository;

    private TranslatorInterface&MockObject $translator;

    private AbstractGatewayConfigurationType $type;

    protected function setUp(): void
    {
        $this->gatewayConfigRepository = $this->createMock(RepositoryInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $this->type = new AbstractGatewayConfigurationType(
            $this->translator,
            $this->gatewayConfigRepository,
            $this->createMock(RequestStack::class),
        );
    }

    /**
     * Every PayPlug-family factory, including `payplug` itself, is limited to one PaymentMethod.
     */
    public function testCanBeCreated_otherFactoryAlreadyConfigured_isRefused(): void
    {
        $this->gatewayConfigRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['factoryName' => OneyGatewayFactory::FACTORY_NAME])
            ->willReturn($this->createMock(GatewayConfigInterface::class))
        ;

        self::assertFalse($this->canBeCreated(OneyGatewayFactory::FACTORY_NAME));
    }

    public function testCanBeCreated_otherFactoryNotYetConfigured_isAllowed(): void
    {
        $this->gatewayConfigRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['factoryName' => OneyGatewayFactory::FACTORY_NAME])
            ->willReturn(null)
        ;

        self::assertTrue($this->canBeCreated(OneyGatewayFactory::FACTORY_NAME));
    }

    private function canBeCreated(string $factoryName): bool
    {
        $method = new \ReflectionMethod(AbstractGatewayConfigurationType::class, 'canBeCreated');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($this->type, $factoryName);

        return $result;
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
