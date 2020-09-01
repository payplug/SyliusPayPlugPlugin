<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneyRulesExtension extends AbstractExtension
{
    /** @var \Sylius\Component\Order\Context\CartContextInterface */
    private $cartContext;

    /** @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface */
    private $oneyChecker;

    /** @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface */
    private $oneyClient;

    /** @var \Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface */
    private $moneyFormatter;

    public function __construct(
        OneyCheckerInterface $oneyChecker,
        CartContextInterface $cartContext,
        PayPlugApiClientInterface $oneyClient,
        MoneyFormatterInterface $moneyFormatter
    ) {
        $this->oneyChecker = $oneyChecker;
        $this->cartContext = $cartContext;
        $this->oneyClient = $oneyClient;
        $this->moneyFormatter = $moneyFormatter;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_cart_eligible', [$this, 'isCartEligible']),
            new TwigFunction('oney_ineligible_reasons', [$this, 'getReasonsOfIneligibility']),
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
        } catch (\Throwable $throwable) {
            // unable to find currency_code
            return false;
        }

        return $this->oneyChecker->isPriceEligible($currentCart->getTotal(), $currencyCode);
    }

    public function getReasonsOfIneligibility(): array
    {
        $data = [];
        $transParam = [];
        /** @var \Sylius\Component\Core\Model\Order $currentCart */
        $currentCart = $this->cartContext->getCart();

        if ($currentCart->getTotalQuantity() > 999) {
            $data[] = 'payplug_sylius_payplug_plugin.ui.too_much_quantity';
            $transParam[] = ['%max_articles%' => 1000];
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
                        $currentCart->getLocaleCode()
                    ),
                ];
                $transParam[] = [
                    '%max_amount%' => $this->moneyFormatter->format(
                        $account['configuration']['oney']['max_amounts'][$currencyCode],
                        $currencyCode,
                        $currentCart->getLocaleCode()
                    ),
                ];
            }
        } catch (\Throwable $throwable) {
            // do nothing
        }

        return [
            'reasons' => $data,
            'trans_params' => array_merge([], ...$transParam),
        ];
    }
}
