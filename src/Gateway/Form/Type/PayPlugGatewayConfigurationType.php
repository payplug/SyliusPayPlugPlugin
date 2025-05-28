<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug',
        'label' => 'payplug_sylius_payplug_plugin.ui.payplug_gateway_label'
    ]
)]
final class PayPlugGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = PayPlugGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = PayPlugGatewayFactory::BASE_CURRENCY_CODE;
}
