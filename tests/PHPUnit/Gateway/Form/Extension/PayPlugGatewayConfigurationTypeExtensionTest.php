<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PayPlugGatewayConfigurationTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PayPlugGatewayConfigurationTypeExtensionTest extends TestCase
{
    private PayPlugGatewayConfigurationTypeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new PayPlugGatewayConfigurationTypeExtension($this->createMock(TranslatorInterface::class));
    }

    public function testBuildForm_addsOneClickCheckboxField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[0];
        self::assertSame(PayPlugGatewayFactory::ONE_CLICK, $name);
        self::assertSame(CheckboxType::class, $type);
        self::assertSame('payplug_sylius_payplug_plugin.form.one_click_enable', $options['label']);
        self::assertFalse($options['required']);
    }

    public function testBuildForm_addsDeferredCaptureCheckboxField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[1];
        self::assertSame(PayPlugGatewayFactory::DEFERRED_CAPTURE, $name);
        self::assertSame(CheckboxType::class, $type);
        self::assertSame('payplug_sylius_payplug_plugin.form.deferred_capture_enable', $options['label']);
        self::assertFalse($options['required']);
    }

    public function testBuildForm_addsDisplayModeChoiceField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[2];
        self::assertSame(PayPlugGatewayFactory::DISPLAY_MODE_FIELD, $name);
        self::assertSame(ChoiceType::class, $type);
        self::assertFalse($options['mapped']);
        self::assertFalse($options['required']);
        self::assertTrue($options['expanded']);
        self::assertSame(
            [
                'payplug_sylius_payplug_plugin.form.integrated_payment_enable' => PayPlugGatewayFactory::DISPLAY_MODE_INTEGRATED_PAYMENT,
                'payplug_sylius_payplug_plugin.ui.hosted_fields_option' => PayPlugGatewayFactory::DISPLAY_MODE_HOSTED_FIELDS,
            ],
            $options['choices'],
        );
    }

    public function testBuildForm_addsHfIdentifierTextField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[3];
        self::assertSame(PayPlugGatewayFactory::HF_IDENTIFIER, $name);
        self::assertSame(TextType::class, $type);
        self::assertSame('payplug_sylius_payplug_plugin.ui.hf_identifier_label', $options['label']);
        self::assertFalse($options['required']);
    }

    public function testBuildForm_addsHfSubMerchantIdPasswordField(): void
    {
        [, $addCalls] = $this->buildFormAndCollectAddCalls();

        [$name, $type, $options] = $addCalls[4];
        self::assertSame(PayPlugGatewayFactory::HF_SUB_MERCHANT_ID, $name);
        self::assertSame(PasswordType::class, $type);
        self::assertSame('payplug_sylius_payplug_plugin.ui.hf_sub_merchant_id_label', $options['label']);
        self::assertFalse($options['required']);
    }

    public function testGetExtendedTypes_returnsPayPlugGatewayConfigurationType(): void
    {
        self::assertSame([PayPlugGatewayConfigurationType::class], PayPlugGatewayConfigurationTypeExtension::getExtendedTypes());
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
        $builder->method('addEventListener')->willReturn($builder);

        $this->extension->buildForm($builder, []);

        return [$builder, $addCalls];
    }
}
