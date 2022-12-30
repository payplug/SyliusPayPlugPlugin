<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;

final class BancontactGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $noTestKeyMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_with_test_key';

    protected string $noAccessMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_no_access';

    protected string $gatewayFactoryTitle = BancontactGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = BancontactGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = BancontactGatewayFactory::BASE_CURRENCY_CODE;
}
