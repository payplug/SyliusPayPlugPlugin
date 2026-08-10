<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
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

    private AbstractGatewayConfigurationType $type;

    protected function setUp(): void
    {
        $this->gatewayConfigRepository = $this->createMock(RepositoryInterface::class);

        $this->type = new AbstractGatewayConfigurationType(
            $this->createMock(TranslatorInterface::class),
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
}
