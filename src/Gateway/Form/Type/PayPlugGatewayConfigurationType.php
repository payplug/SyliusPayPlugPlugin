<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;

final class PayPlugGatewayConfigurationType extends AbstractGatewayConfigurationType
{
    protected string $gatewayFactoryTitle = PayPlugGatewayFactory::FACTORY_TITLE;

    protected string $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;

    protected string $gatewayBaseCurrencyCode = PayPlugGatewayFactory::BASE_CURRENCY_CODE;
}
