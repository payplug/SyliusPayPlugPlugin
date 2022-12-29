<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;

final class OneyGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = OneyGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = OneyGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = OneyGatewayFactory::BASE_CURRENCY_CODE;
}
