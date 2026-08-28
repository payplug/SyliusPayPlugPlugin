<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GetPayPlugApiUrlExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('payplug_get_api_url', $this->getApiUrl(...)),
        ];
    }

    public function getApiUrl(): string
    {
        return \Payplug\Core\APIRoutes::$API_BASE_URL;
    }
}
