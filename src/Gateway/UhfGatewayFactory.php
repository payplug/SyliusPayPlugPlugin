<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

final class UhfGatewayFactory extends AbstractGatewayFactory
{
    public const FACTORY_NAME = 'payplug_uhf';

    public const FACTORY_TITLE = 'Unified Hosted Fields by PayPlug';

    public const HF_IDENTIFIER_DEFAULT = 'hfIdentifierDefault';

    public const ONE_CLICK = 'oneClick';
}
