<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_uhf',
        'label' => 'payplug_sylius_payplug_plugin.ui.uhf_gateway_label',
        'priority' => 80,
    ],
)]
final class UhfGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = UhfGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = UhfGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = UhfGatewayFactory::BASE_CURRENCY_CODE;
}
