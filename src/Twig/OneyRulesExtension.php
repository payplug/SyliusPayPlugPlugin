<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
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
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface
     */
    private $oneyClient;
    /**
     * @var \Sylius\Bundle\MoneyBundle\Templating\Helper\ConvertMoneyHelperInterface
     */
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

        $currency = $currentCart->getChannel()->getBaseCurrency()->getCode();

        if (null !== $currency && !$this->oneyChecker->isPriceEligible($currentCart->getTotal(), $currency)) {
            $data[] = 'payplug_sylius_payplug_plugin.ui.invalid_cart_price';
            $account = $this->oneyClient->getAccount();

            $transParam[] = [
                '%min_amount%' => $this->moneyFormatter->format(
                    $account['configuration']['oney']['min_amounts'][$currency],
                    $currency,
                    $currentCart->getLocaleCode()
                )
            ];
            $transParam[] = [
                '%max_amount%' => $this->moneyFormatter->format(
                    $account['configuration']['oney']['max_amounts'][$currency],
                    $currency,
                    $currentCart->getLocaleCode()
                )
            ];
        }

        return [
            'reasons' => $data,
            'trans_params' => array_merge(...$transParam)
        ];
    }
}
