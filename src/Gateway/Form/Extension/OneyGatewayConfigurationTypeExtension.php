<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\OneyGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class OneyGatewayConfigurationTypeExtension extends AbstractTypeExtension
{
    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(OneyGatewayFactory::FEES_FOR, ChoiceType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.oney_gateway_config.fees_for.title',
                'choices' => [
                    'payplug_sylius_payplug_plugin.ui.oney_gateway_config.fees_for.client' => 'client',
                    'payplug_sylius_payplug_plugin.ui.oney_gateway_config.fees_for.merchant' => 'merchant',
                ],
                'expanded' => true,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
                'constraints' => [
                    new NotBlank([]),
                ],
            ])
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [OneyGatewayConfigurationType::class];
    }
}
