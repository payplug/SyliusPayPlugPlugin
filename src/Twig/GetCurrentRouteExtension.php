<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GetCurrentRouteExtension extends AbstractExtension
{
    /** @var Request|null */
    private $request;

    public function __construct(
        RequestStack $requestStack,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payplug_get_current_route', $this->payplugGetCurrentRoute(...)),
        ];
    }

    public function payplugGetCurrentRoute(): string
    {
        if (!$this->request instanceof Request) {
            return '';
        }

        return $this->request->get('_route', '');
    }
}
