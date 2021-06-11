<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class OneyGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_oney';

    public const FACTORY_TITLE = 'Oney by PayPlug';

    public const MAX_ITEMS = 999;

    public const REFUND_WAIT_TIME_IN_HOURS = 48;

    public const PAYMENT_CHOICES = [
        'oney_x3_with_fees',
        'oney_x4_with_fees',
    ];
}
