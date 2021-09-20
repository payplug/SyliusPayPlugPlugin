<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class PayPlugGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug';

    public const FACTORY_TITLE = 'PayPlug';

    public const ONE_CLICK = 'oneClick';

    public const AUTHORIZED_CURRENCIES = [
        'EUR' => [
            'min_amount' => 99,
            'max_amount' => 2000000,
        ],
    ];
}
