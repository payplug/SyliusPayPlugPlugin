<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Webmozart\Assert\Assert;

final class OneyRulesExtension extends AbstractExtension
{
    public function __construct(
        private OneyCheckerInterface $oneyChecker,
        private CartContextInterface $cartContext,
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.oney')]
        private PayPlugApiClientInterface $oneyClient,
        private MoneyFormatterInterface $moneyFormatter,
        private ProductRepositoryInterface $productRepository,
        private RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_cart_eligible', $this->isCartEligible(...)),
            new TwigFunction('oney_product_eligible', $this->isProductEligible(...)),
            new TwigFunction('oney_ineligible_reasons', $this->getReasonsOfIneligibility(...)),
        ];
    }

    public function isCartEligible(?OrderInterface $currentCart = null): bool
    {
        if (!$currentCart instanceof \Sylius\Component\Core\Model\OrderInterface) {
            /** @var OrderInterface $currentCart */
            $currentCart = $this->cartContext->getCart();
        }

        if (!$this->oneyChecker->isNumberOfProductEligible($currentCart->getTotalQuantity())) {
            return false;
        }

        try {
            $channel = $currentCart->getChannel();
            if (!$channel instanceof ChannelInterface) {
                throw new \LogicException('No channel found');
            }

            $currency = $channel->getBaseCurrency();
            if (!$currency instanceof CurrencyInterface) {
                throw new \LogicException('No currency found');
            }

            $currencyCode = $currency->getCode();
            if (null === $currencyCode) {
                throw new \LogicException('No currency code found');
            }
        } catch (\Throwable) {
            // unable to find currency_code
            return false;
        }

        return $this->oneyChecker->isPriceEligible($currentCart->getTotal(), $currencyCode);
    }

    public function getReasonsOfIneligibility(?OrderInterface $currentCart = null): array
    {
        $data = [];
        $transParam = [];

        if (!$currentCart instanceof \Sylius\Component\Core\Model\OrderInterface) {
            /** @var OrderInterface $currentCart */
            $currentCart = $this->cartContext->getCart();
        }

        if (!$this->oneyChecker->isNumberOfProductEligible($currentCart->getTotalQuantity())) {
            $data[] = 'payplug_sylius_payplug_plugin.ui.too_much_quantity';
            $transParam[] = ['%max_articles%' => OneyGatewayFactory::MAX_ITEMS + 1];
        }

        try {
            $channel = $currentCart->getChannel();
            if (!$channel instanceof ChannelInterface) {
                throw new \LogicException('No channel found');
            }

            $currency = $channel->getBaseCurrency();
            if (!$currency instanceof CurrencyInterface) {
                throw new \LogicException('No currency found');
            }

            $currencyCode = $currency->getCode();
            if (null === $currencyCode) {
                throw new \LogicException('No currency code found');
            }

            if (!$this->oneyChecker->isPriceEligible($currentCart->getTotal(), $currencyCode)) {
                $data[] = 'payplug_sylius_payplug_plugin.ui.invalid_cart_price';
                $account = $this->oneyClient->getAccount();
                $transParam[] = [
                    '%min_amount%' => $this->moneyFormatter->format(
                        $account['configuration']['oney']['min_amounts'][$currencyCode],
                        $currencyCode,
                        $currentCart->getLocaleCode(),
                    ),
                ];
                $transParam[] = [
                    '%max_amount%' => $this->moneyFormatter->format(
                        $account['configuration']['oney']['max_amounts'][$currencyCode],
                        $currencyCode,
                        $currentCart->getLocaleCode(),
                    ),
                ];
            }
        } catch (\Throwable) {
            // do nothing
        }

        return [
            'reasons' => $data,
            'trans_params' => array_merge([], ...$transParam),
        ];
    }

    public function isProductEligible(): bool
    {
        /** @var OrderInterface $currentCart */
        $currentCart = $this->cartContext->getCart();

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || 'sylius_shop_product_show' !== $request->get('_route')) {
            return false;
        }

        try {
            $channel = $currentCart->getChannel();
            if (!$channel instanceof ChannelInterface) {
                throw new \LogicException('No channel found');
            }

            $currency = $channel->getBaseCurrency();
            if (!$currency instanceof CurrencyInterface) {
                throw new \LogicException('No currency found');
            }

            $currencyCode = $currency->getCode();
            if (null === $currencyCode) {
                throw new \LogicException('No currency code found');
            }
        } catch (\Throwable) {
            // unable to find currency_code
            return false;
        }

        Assert::notNull($currentCart->getLocaleCode());
        Assert::isArray($request->get('_route_params'));
        Assert::keyExists($request->get('_route_params'), 'slug');
        Assert::string($request->get('_route_params')['slug']);

        $product = $this->productRepository->findOneByChannelAndSlug(
            $channel,
            $currentCart->getLocaleCode(),
            $request->get('_route_params')['slug'],
        );

        Assert::isInstanceOf($product, ProductInterface::class);

        /** @var ProductVariantInterface|null $firstVariant */
        $firstVariant = $product->getEnabledVariants()->first();

        if (null === $firstVariant) {
            return false;
        }

        $pricing = $firstVariant->getChannelPricingForChannel($channel);

        if (null === $pricing) {
            return false;
        }

        Assert::notNull($pricing->getPrice());

        return $this->oneyChecker->isPriceEligible($pricing->getPrice(), $currencyCode);
    }
}
