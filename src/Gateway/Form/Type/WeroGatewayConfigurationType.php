<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_wero',
        'label' => 'payplug_sylius_payplug_plugin.ui.wero_gateway_label',
        'priority' => 90,
    ],
)]
final class WeroGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = WeroGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = WeroGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = WeroGatewayFactory::BASE_CURRENCY_CODE;
}
