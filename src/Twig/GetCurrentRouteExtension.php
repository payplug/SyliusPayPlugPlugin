<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GetCurrentRouteExtension extends AbstractExtension
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payplug_get_current_route', $this->payplugGetCurrentRoute(...)),
        ];
    }

    public function payplugGetCurrentRoute(): string
    {
        $currentRequest = $this->requestStack->getCurrentRequest();
        if (!$currentRequest instanceof Request) {
            return '';
        }

        return $currentRequest->get('_route', '');
    }
}
