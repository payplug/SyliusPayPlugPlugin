<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class ApplePayGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_apple_pay';

    public const FACTORY_TITLE = 'Apple Pay by PayPlug';

    public const PAYMENT_METHOD_BANCONTACT = 'apple_pay';

    public const AUTHORIZED_CURRENCIES = [
        'EUR' => [
            'min_amount' => 100,
            'max_amount' => 2000000,
        ],
    ];
}
