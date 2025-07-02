<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class PayPlugSyliusPayPlugPlugin extends Bundle
{
    public const VERSION = '1.11.1';

    use SyliusPluginTrait;
}
