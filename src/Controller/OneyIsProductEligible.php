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
        /** @var OrderInterface $cart */
        $cart = $this->cartContext->getCart();

        $productVariantCode = $request->get('product_variant_code');
        Assert::notNull($productVariantCode);

        /** @var int|null $quantity */
        $quantity = (int) $request->get('quantity');
        Assert::notNull($quantity);

        return new JsonResponse([
            'isEligible' => $this->isProductEligible($cart, $productVariantCode, $quantity),
        ]);
    }

    private function isProductEligible(
        OrderInterface $cart,
        string $productVariantCode,
        int $quantity
    ): bool {
        $productVariant = $this->productVariantRepository->findOneBy(['code' => $productVariantCode]);
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
