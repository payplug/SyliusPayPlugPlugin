<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class WeroGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_wero';

    public const FACTORY_TITLE = 'Wero by PayPlug';

    public const PAYMENT_METHOD_WERO = 'wero';
}
