<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\UhfGatewayConfigurationTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\UhfGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UhfGatewayConfigurationTypeExtensionTest extends TestCase
{
    private UhfGatewayConfigurationTypeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new UhfGatewayConfigurationTypeExtension();
    }

    public function testBuildForm_addsHfIdentifierDefaultTextField(): void
    {
        $builder = $this->createMock(FormBuilderInterface::class);

        $addCalls = [];
        $builder
            ->expects(self::once())
            ->method('add')
            ->willReturnCallback(function ($name, $type = null, array $options = []) use (&$addCalls, $builder) {
                $addCalls[] = [$name, $type, $options];

                return $builder;
            })
        ;

        $this->extension->buildForm($builder, []);

        [$name, $type, $options] = $addCalls[0];
        self::assertSame(UhfGatewayFactory::HF_IDENTIFIER_DEFAULT, $name);
        self::assertSame(TextType::class, $type);
        self::assertSame(
            'payplug_sylius_payplug_plugin.ui.hf_identifier_default_label',
            $options['label'],
        );
        self::assertTrue($options['required']);
        self::assertSame(AbstractGatewayConfigurationType::VALIDATION_GROUPS, $options['validation_groups']);
        self::assertCount(1, $options['constraints']);
        self::assertInstanceOf(NotBlank::class, $options['constraints'][0]);
    }

    public function testGetExtendedTypes_returnsUhfGatewayConfigurationType(): void
    {
        self::assertSame([UhfGatewayConfigurationType::class], UhfGatewayConfigurationTypeExtension::getExtendedTypes());
    }
}
