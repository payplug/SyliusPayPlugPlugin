<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\ScalapayGatewayConfigurationTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;

final class ScalapayGatewayConfigurationTypeExtensionTest extends TestCase
{
    private ScalapayGatewayConfigurationTypeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ScalapayGatewayConfigurationTypeExtension();
    }

    public function testBuildForm_addsMinAmountMoneyField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[0];
        self::assertSame(ScalapayGatewayFactory::MIN_AMOUNT, $name);
        self::assertSame(MoneyType::class, $type);
        self::assertSame('EUR', $options['currency']);
        self::assertFalse($options['required']);
    }

    public function testBuildForm_addsMaxAmountMoneyField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[1];
        self::assertSame(ScalapayGatewayFactory::MAX_AMOUNT, $name);
        self::assertSame(MoneyType::class, $type);
        self::assertSame('EUR', $options['currency']);
        self::assertFalse($options['required']);
    }

    public function testGetExtendedTypes_returnsScalapayGatewayConfigurationType(): void
    {
        self::assertSame([ScalapayGatewayConfigurationType::class], ScalapayGatewayConfigurationTypeExtension::getExtendedTypes());
    }

    /**
     * @return array{0: FormBuilderInterface, 1: array<int, array{0: string, 1: string, 2: array<string, mixed>}>}
     */
    private function buildFormAndCollectAddCalls(): array
    {
        $builder = $this->createMock(FormBuilderInterface::class);

        $addCalls = [];
        $builder
            ->method('add')
            ->willReturnCallback(function ($name, $type = null, array $options = []) use (&$addCalls, $builder) {
                $addCalls[] = [$name, $type, $options];

                return $builder;
            })
        ;

        $this->extension->buildForm($builder, []);

        return [$builder, $addCalls];
    }
}
