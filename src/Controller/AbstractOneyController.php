<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use PayPlug\SyliusPayPlugPlugin\Twig\OneyRulesExtension;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Webmozart\Assert\Assert;

#[AsController]
abstract class AbstractOneyController extends AbstractController
{
    public function __construct(
        protected OneySimulationDataProviderInterface $oneySimulationDataProvider,
        protected CartContextInterface $cartContext,
        protected ProductRepository $productRepository,
        protected ProductVariantRepository $productVariantRepository,
        protected FactoryInterface $orderFactory,
        protected FactoryInterface $orderItemFactory,
        protected OrderModifierInterface $orderModifier,
        protected OrderItemQuantityModifierInterface $itemQuantityModifier,
        protected OneyRulesExtension $oneyRulesExtension,
    ) {
    }

    protected function createTempCart(
        ProductVariantInterface $productVariant,
        int $quantity,
        ChannelInterface $channel,
        string $localeCode,
        string $currencyCode,
    ): OrderInterface {
        /** @var OrderInterface $tempCart */
        $tempCart = $this->orderFactory->createNew();
        $tempCart->setChannel($channel);
        $tempCart->setLocaleCode($localeCode);
        $tempCart->setCurrencyCode($currencyCode);

        /** @var OrderItemInterface $orderItem */
        $orderItem = $this->orderItemFactory->createNew();
        $orderItem->setVariant($productVariant);

        $channelPricing = $productVariant->getChannelPricingForChannel($channel);
        Assert::isInstanceOf($channelPricing, ChannelPricingInterface::class);

        $price = $channelPricing->getPrice();
        Assert::notNull($price);
        $orderItem->setUnitPrice($price);

        $this->itemQuantityModifier->modify($orderItem, $quantity);
        $this->orderModifier->addToOrder($tempCart, $orderItem);

        return $tempCart;
    }
}
