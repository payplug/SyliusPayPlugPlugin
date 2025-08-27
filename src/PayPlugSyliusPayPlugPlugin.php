<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class PayPlugSyliusPayPlugPlugin extends Bundle
{
    use SyliusPluginTrait;

    public const VERSION = '2.x-dev';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
