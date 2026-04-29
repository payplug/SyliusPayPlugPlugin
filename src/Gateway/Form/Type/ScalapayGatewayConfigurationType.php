<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_scalapay',
        'label' => 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_label',
        'priority' => 80,
    ],
)]
final class ScalapayGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = ScalapayGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = ScalapayGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = ScalapayGatewayFactory::BASE_CURRENCY_CODE;
}
