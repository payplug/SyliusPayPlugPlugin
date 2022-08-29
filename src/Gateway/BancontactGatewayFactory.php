<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class BancontactGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_bancontact';

    public const FACTORY_TITLE = 'Bancontact by PayPlug';

    public const AUTHORIZED_CURRENCIES = [
        'EUR' => [
            'min_amount' => 99,
            'max_amount' => 2000000,
        ],
    ];
}
