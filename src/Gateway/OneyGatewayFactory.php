<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class OneyGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_oney';

    public const FACTORY_TITLE = 'Oney by PayPlug';

    public const MERCHANT_FEES = 'merchant';

    public const CLIENT_FEES = 'client';

    public const MAX_ITEMS = 999;

    public const REFUND_WAIT_TIME_IN_HOURS = 48;

    public const FEES_FOR = 'fees_for';

    public const ONEY_WITH_FEES_CHOICES = [
        'x3_with_fees',
        'x4_with_fees',
    ];

    public const ONEY_WITHOUT_FEES_CHOICES = [
        'x3_without_fees',
        'x4_without_fees',
    ];

    public const PAYMENT_CHOICES_FEES_FOR = [
        'merchant' => self::ONEY_WITHOUT_FEES_CHOICES,
        'client' => self::ONEY_WITH_FEES_CHOICES,
    ];

    public const PAYMENT_CHOICES = [
        'oney_x3_with_fees',
        'oney_x4_with_fees',
        'oney_x3_without_fees',
        'oney_x4_without_fees',
    ];
}
