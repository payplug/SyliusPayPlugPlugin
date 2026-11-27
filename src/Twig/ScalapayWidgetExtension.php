<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Declares the Scalapay widget functions; they are served by {@see ScalapayWidgetRuntime}, which
 * Twig builds on first use.
 */
final class ScalapayWidgetExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('payplug_scalapay_product_widget', [ScalapayWidgetRuntime::class, 'forProduct']),
            new TwigFunction('payplug_scalapay_cart_widget', [ScalapayWidgetRuntime::class, 'forCart']),
            new TwigFunction('payplug_scalapay_checkout_widget', [ScalapayWidgetRuntime::class, 'forCheckout']),
            new TwigFunction('payplug_scalapay_widget_script_required', [ScalapayWidgetRuntime::class, 'isScriptRequired']),
        ];
    }
}
