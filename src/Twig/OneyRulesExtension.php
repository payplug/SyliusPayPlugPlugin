<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneyRulesExtension extends AbstractExtension
{
    /**
     * @var \Sylius\Component\Order\Context\CartContextInterface
     */
    private $cartContext;
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface
     */
    private $oneyChecker;

    public function __construct(
        OneyCheckerInterface $oneyChecker,
        CartContextInterface $cartContext
    ) {
        $this->oneyChecker = $oneyChecker;
        $this->cartContext = $cartContext;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_cart_eligible', [$this, 'isCartEligible']),
        ];
    }

    public function isCartEligible(): bool
    {
        /** @var \Sylius\Component\Core\Model\Order $currentCart */
        $currentCart = $this->cartContext->getCart();
        if ($currentCart->getTotalQuantity() > 999) {
            // TODO change this value to one from gateway
            return false;
        }

        try {
            $currency = $currentCart->getChannel()->getBaseCurrency()->getCode();
            if (null === $currency) {
                throw new \LogicException('No currency code found');
            }
        } catch (\Throwable $throwable) {
            // unable to find currency_code
            return false;
        }

        return $this->oneyChecker->isPriceEligible($currentCart->getTotal(), $currency);
    }
}
