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
use Webmozart\Assert\Assert;

abstract class AbstractOneyController extends AbstractController
{
    /** @var OneySimulationDataProviderInterface */
    protected $oneySimulationDataProvider;

    /** @var CartContextInterface */
    protected $cartContext;

    /** @var \Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository */
    protected $productVariantRepository;

    /** @var FactoryInterface */
    protected $cartFactory;

    /** @var FactoryInterface */
    protected $orderItemFactory;

    /** @var OrderModifierInterface */
    protected $orderModifier;

    /** @var OrderItemQuantityModifierInterface */
    protected $itemQuantityModifier;

    /** @var OneyRulesExtension */
    protected $oneyRulesExtension;

    /** @var ProductRepository */
    protected $productRepository;

    public function __construct(
        OneySimulationDataProviderInterface $oneySimulationDataProvider,
        CartContextInterface $cartContext,
        ProductRepository $productRepository,
        ProductVariantRepository $productVariantRepository,
        FactoryInterface $orderFactory,
        FactoryInterface $orderItemFactory,
        OrderModifierInterface $orderModifier,
        OrderItemQuantityModifierInterface $itemQuantityModifier,
        OneyRulesExtension $oneyRulesExtension
    ) {
        $this->oneySimulationDataProvider = $oneySimulationDataProvider;
        $this->cartContext = $cartContext;
        $this->productVariantRepository = $productVariantRepository;
        $this->cartFactory = $orderFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->orderModifier = $orderModifier;
        $this->itemQuantityModifier = $itemQuantityModifier;
        $this->oneyRulesExtension = $oneyRulesExtension;
        $this->productRepository = $productRepository;
    }

    protected function createTempCart(
        ProductVariantInterface $productVariant,
        int $quantity,
        ChannelInterface $channel,
        string $localeCode,
        string $currencyCode
    ): OrderInterface {
        /** @var OrderInterface $tempCart */
        $tempCart = $this->cartFactory->createNew();
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
