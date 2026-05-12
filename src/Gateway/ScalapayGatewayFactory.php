<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class ScalapayGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_scalapay';

    public const FACTORY_TITLE = 'Scalapay by PayPlug';

    public const PAYMENT_METHOD_SCALAPAY = 'scalapay';
}
