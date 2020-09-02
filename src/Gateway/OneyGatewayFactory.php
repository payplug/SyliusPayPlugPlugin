<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class OneyGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_oney';

    public const FACTORY_TITLE = 'Oney by PayPlug';

    public const MAX_ITEMS = 999;
}
