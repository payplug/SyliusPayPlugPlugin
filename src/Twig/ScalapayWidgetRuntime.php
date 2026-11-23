<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidget;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetResolver;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Loaded by Twig only when one of the Scalapay widget functions is called, so the resolver and its
 * dependencies are not built on the pages that never use them (administration, e-mails).
 */
final class ScalapayWidgetRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ScalapayWidgetResolver $resolver,
        private RequestStack $requestStack,
    ) {
    }

    public function forProduct(?ProductVariantInterface $variant): ?ScalapayWidget
    {
        return $this->resolver->forProduct($variant);
    }

    public function forCart(): ?ScalapayWidget
    {
        return $this->resolver->forCart();
    }

    public function forCheckout(OrderInterface $order, PaymentMethodInterface $paymentMethod): ?ScalapayWidget
    {
        return $this->resolver->forCheckout($order, $paymentMethod);
    }

    /**
     * Read from the main request: the page layout, where the script goes, is only rendered there.
     */
    public function isScriptRequired(): bool
    {
        $route = $this->requestStack->getMainRequest()?->attributes->get('_route');

        return \is_string($route) && $this->resolver->isScriptRequired($route);
    }
}
