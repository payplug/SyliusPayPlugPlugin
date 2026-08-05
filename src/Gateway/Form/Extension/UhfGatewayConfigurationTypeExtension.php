<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\UhfGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UhfGatewayConfigurationTypeExtension extends AbstractTypeExtension
{
    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(UhfGatewayFactory::HF_IDENTIFIER_DEFAULT, TextType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.hf_identifier_default_label',
                'required' => true,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'constraints' => [
                    new NotBlank([]),
                ],
            ])
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [UhfGatewayConfigurationType::class];
    }
}
