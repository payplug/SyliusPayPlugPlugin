<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class BancontactGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_bancontact';

    public const FACTORY_TITLE = 'Bancontact by PayPlug';

    public const PAYMENT_METHOD_BANCONTACT = 'bancontact';

    public const AUTHORIZED_CURRENCIES = [
        'EUR' => [
            'min_amount' => 100,
            'max_amount' => 2000000,
        ],
    ];
}
