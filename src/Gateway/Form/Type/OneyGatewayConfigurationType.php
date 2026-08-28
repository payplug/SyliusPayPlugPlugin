<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_oney',
        'label' => 'payplug_sylius_payplug_plugin.ui.oney_gateway_label',
        'priority' => 90,
    ],
)]
final class OneyGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = OneyGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = OneyGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = OneyGatewayFactory::BASE_CURRENCY_CODE;
}
