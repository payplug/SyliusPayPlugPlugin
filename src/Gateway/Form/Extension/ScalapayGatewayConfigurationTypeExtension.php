<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\AbstractGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\ScalapayGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

final class ScalapayGatewayConfigurationTypeExtension extends AbstractTypeExtension
{
    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(ScalapayGatewayFactory::MIN_AMOUNT, MoneyType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.min_amount',
                'help' => 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.amount_help',
                'currency' => 'EUR',
                'required' => false,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
            ])
            ->add(ScalapayGatewayFactory::MAX_AMOUNT, MoneyType::class, [
                'label' => 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.max_amount',
                'help' => 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.amount_help',
                'currency' => 'EUR',
                'required' => false,
                'validation_groups' => AbstractGatewayConfigurationType::VALIDATION_GROUPS,
            ])
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [ScalapayGatewayConfigurationType::class];
    }
}
