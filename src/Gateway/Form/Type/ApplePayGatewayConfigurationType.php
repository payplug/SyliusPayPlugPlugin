<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;

final class ApplePayGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $noTestKeyMessage = 'payplug_sylius_payplug_plugin.apple_pay.can_not_save_method_with_test_key';

    protected string $noAccessMessage = 'payplug_sylius_payplug_plugin.apple_pay.can_not_save_method_no_access';

    protected string $gatewayFactoryTitle = ApplePayGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = ApplePayGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = ApplePayGatewayFactory::BASE_CURRENCY_CODE;
}
