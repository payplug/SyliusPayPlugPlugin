<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

final class OneyIsProductEligible extends AbstractOneyController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var \Sylius\Component\Core\Model\OrderInterface $cart */
        $cart = $this->cartContext->getCart();

        $productCode = $request->get('product');
        Assert::notNull($productCode);

        /** @var string|null $productOptionValue */
        $productOptionValue = $request->get('option');

        /** @var int|null $quantity */
        $quantity = (int) $request->get('quantity');
        Assert::notNull($quantity);

        return new JsonResponse([
            'isEligible' => $this->isProductEligible($cart, $productCode, $quantity, $productOptionValue),
        ]);
    }

    private function isProductEligible(
        OrderInterface $cart,
        string $productCode,
        int $quantity,
        ?string $productOptionValue
    ): bool {
        $productVariant = $this->getProductVariant($productCode, $productOptionValue);
        Assert::isInstanceOf($productVariant, ProductVariantInterface::class);

        $channel = $cart->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        Assert::notNull($cart->getLocaleCode());
        Assert::notNull($cart->getCurrencyCode());

        $tempCart = $this->createTempCart(
            $productVariant,
            $quantity,
            $channel,
            $cart->getLocaleCode(),
            $cart->getCurrencyCode()
        );

        return $this->oneyRulesExtension->isCartEligible($tempCart);
    }
}
