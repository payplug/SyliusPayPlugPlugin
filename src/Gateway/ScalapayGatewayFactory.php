<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class ScalapayGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_scalapay';

    public const FACTORY_TITLE = 'Scalapay by PayPlug';

    public const PAYMENT_METHOD_SCALAPAY = 'scalapay';

    /** @deprecated no longer read; the merchant range is stored under the `payment_method` config key */
    public const MIN_AMOUNT = 'min_amount';

    /** @deprecated no longer read; the merchant range is stored under the `payment_method` config key */
    public const MAX_AMOUNT = 'max_amount';
}
