<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'sylius.gateway_configuration_type',
    [
        'type' => 'payplug_apple_pay',
        'label' => 'payplug_sylius_payplug_plugin.ui.apple_pay_gateway_label',
        'priority' => 70,
    ],
)]
final class ApplePayGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = ApplePayGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = ApplePayGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = ApplePayGatewayFactory::BASE_CURRENCY_CODE;
}
