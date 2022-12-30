<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class AmericanExpressGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_american_express';

    public const FACTORY_TITLE = 'American Express by PayPlug';

    public const PAYMENT_METHOD_AMERICAN_EXPRESS = 'american_express';

    public const AUTHORIZED_CURRENCIES = [
        'EUR' => [
            'min_amount' => 100,
            'max_amount' => 2000000,
        ],
    ];
}
