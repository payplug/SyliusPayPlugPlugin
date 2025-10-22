<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_american_express',
        'label' => 'payplug_sylius_payplug_plugin.ui.american_express_gateway_label',
        'priority' => 70,
    ],
)]
final class AmericanExpressGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = AmericanExpressGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = AmericanExpressGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = AmericanExpressGatewayFactory::BASE_CURRENCY_CODE;
}
