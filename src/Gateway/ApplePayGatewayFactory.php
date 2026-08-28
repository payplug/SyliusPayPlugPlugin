<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class ApplePayGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_apple_pay';

    public const FACTORY_TITLE = 'Apple Pay by PayPlug';

    public const PAYMENT_METHOD_APPLE_PAY = 'apple_pay';
}
