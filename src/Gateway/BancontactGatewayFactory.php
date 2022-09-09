<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class BancontactGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_bancontact';

    public const FACTORY_TITLE = 'Bancontact by PayPlug';

    public const PAYMENT_METHOD_BANCONTACT = 'bancontact';
}
