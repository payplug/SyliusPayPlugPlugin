<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

#[AsController]
final class OneySimulationPopin extends AbstractOneyController
{
    #[Route(path: '/{_locale}/payplug/oney_popin', name: 'payplug_sylius_oney_simulation_popin', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        /** @var OrderInterface $cart */
        $cart = $this->cartContext->getCart();

        /** @var string|null $productVariantCode */
        $productVariantCode = $request->get('product_variant_code');

        /** @var int|null $quantity */
        $quantity = (int) $request->get('quantity');

        if (null === $productVariantCode || null === $quantity) {
            $simulationData = $this->oneySimulationDataProvider->getForCart($cart);

            return $this->render(
                '@PayPlugSyliusPayPlugPlugin/shop/oney/popin.html.twig',
                [
                    'data' => $simulationData,
                    'ineligibilityData' => $this->oneyRulesExtension->getReasonsOfIneligibility($cart),
                ],
            );
        }

        return $this->renderSimulateForProductVariant($cart, $productVariantCode, $quantity);
    }

    private function renderSimulateForProductVariant(
        OrderInterface $cart,
        string $productVariantCode,
        int $quantity,
    ): Response {
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
            $cart->getCurrencyCode(),
        );

        $simulationData = $this->oneySimulationDataProvider->getForCart($tempCart);

        return $this->render(
            '@PayPlugSyliusPayPlugPlugin/shop/oney/popin.html.twig',
            [
                'data' => $simulationData,
                'ineligibilityData' => $this->oneyRulesExtension->getReasonsOfIneligibility($tempCart),
            ],
        );
    }
}
