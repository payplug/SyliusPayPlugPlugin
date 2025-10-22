<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ProductVariantsCodesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sylius_product_variant_codes', $this->provideVariantsCodes(...)),
        ];
    }

    public function provideVariantsCodes(ProductInterface $product): array
    {
        $variantsPrices = [];

        /** @var ProductVariantInterface $variant */
        foreach ($product->getEnabledVariants() as $variant) {
            $variantsPrices[] = $this->constructOptionsMap($variant);
        }

        return $variantsPrices;
    }

    private function constructOptionsMap(ProductVariantInterface $variant): array
    {
        $optionMap = [];

        /** @var ProductOptionValueInterface $option */
        foreach ($variant->getOptionValues() as $option) {
            $optionMap[$option->getOptionCode()] = $option->getCode();
        }

        $optionMap['value'] = $variant->getCode();

        return $optionMap;
    }
}
