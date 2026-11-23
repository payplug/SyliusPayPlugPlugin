<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Scalapay;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfig;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;

/**
 * Decides whether the Scalapay widget is shown on a storefront page and with which attributes,
 * from the Scalapay payment method of the current channel only: its stored merchant token, its
 * widget settings and the PPRO display eligibility (amount range, billing country). Nothing is
 * fetched from PayPlug here.
 */
final class ScalapayWidgetResolver
{
    /** Languages the Scalapay widget is written in; any other shop language gets English. */
    public const SUPPORTED_LOCALES = ['en', 'it', 'fr', 'es', 'de', 'pt'];

    public const PAGE_BY_ROUTE = [
        'sylius_shop_product_show' => ScalapayWidgetSettings::PAGE_PRODUCT,
        'sylius_shop_cart_summary' => ScalapayWidgetSettings::PAGE_CART,
        'sylius_shop_checkout_select_payment' => ScalapayWidgetSettings::PAGE_CHECKOUT,
    ];

    private const DEFAULT_LOCALE = 'en';

    private const CURRENCY = 'EUR';

    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private ChannelContextInterface $channelContext,
        private CurrencyContextInterface $currencyContext,
        private LocaleContextInterface $localeContext,
        private CartContextInterface $cartContext,
        private ProductVariantPricesCalculatorInterface $pricesCalculator,
        private PproEligibilityChecker $eligibilityChecker,
        private ScalapayWidgetEnvironment $environment,
    ) {
    }

    public function forProduct(?ProductVariantInterface $variant): ?ScalapayWidget
    {
        $channel = $this->channelContext->getChannel();

        if (null === $variant || !$channel instanceof ChannelInterface) {
            return null;
        }

        $amount = $this->pricesCalculator->calculate($variant, ['channel' => $channel]);

        return $this->resolve(ScalapayWidgetSettings::PAGE_PRODUCT, $amount, self::CURRENCY, null, null);
    }

    public function forCart(): ?ScalapayWidget
    {
        $cart = $this->cartContext->getCart();

        return $cart instanceof OrderInterface ? $this->forOrder(ScalapayWidgetSettings::PAGE_CART, $cart, null) : null;
    }

    /**
     * An order already placed (paid again from the order page) is not in checkout.
     *
     * $paymentMethod is the method the widget is displayed under: its token and settings are the
     * ones used, so the widget always belongs to the card above it.
     */
    public function forCheckout(OrderInterface $order, PaymentMethodInterface $paymentMethod): ?ScalapayWidget
    {
        if (OrderInterface::STATE_CART !== $order->getState()) {
            return null;
        }

        return $this->forOrder(ScalapayWidgetSettings::PAGE_CHECKOUT, $order, $paymentMethod);
    }

    /**
     * Whether the widget script is loaded on the page of $route. It does not depend on the amount:
     * a variant or cart change can bring the amount into the range without reloading the page.
     */
    public function isScriptRequired(string $route): bool
    {
        $page = self::PAGE_BY_ROUTE[$route] ?? null;

        return null !== $page && null !== $this->findWidget($page);
    }

    private function forOrder(
        string $page,
        OrderInterface $order,
        ?PaymentMethodInterface $paymentMethod,
    ): ?ScalapayWidget {
        $currencyCode = $order->getCurrencyCode();

        if (null === $currencyCode) {
            return null;
        }

        return $this->resolve($page, $order->getTotal(), $currencyCode, $order->getBillingAddress()?->getCountryCode(), $paymentMethod);
    }

    private function resolve(
        string $page,
        int $amount,
        string $currencyCode,
        ?string $billingCountryCode,
        ?PaymentMethodInterface $paymentMethod,
    ): ?ScalapayWidget {
        $found = $this->findWidget($page, $paymentMethod);

        if (null === $found || true !== $this->eligibilityChecker->isEligible($found[0], $amount, $currencyCode, $billingCountryCode)) {
            return null;
        }

        return $found[1];
    }

    /**
     * @return array{0: PaymentMethodInterface, 1: ScalapayWidget}|null
     */
    private function findWidget(string $page, ?PaymentMethodInterface $shownMethod = null): ?array
    {
        $channel = $this->channelContext->getChannel();

        // Amounts are compared and paid in the channel base currency, while the widget reads the
        // amount displayed on the page: both must be in euros.
        if (
            !$channel instanceof ChannelInterface ||
            self::CURRENCY !== $channel->getBaseCurrency()?->getCode() ||
            self::CURRENCY !== $this->currencyContext->getCurrencyCode()
        ) {
            return null;
        }

        $paymentMethod = $this->scalapayMethod($shownMethod, $channel);
        $config = $paymentMethod?->getGatewayConfig()?->getConfig() ?? [];
        $amountSelector = ScalapayWidgetSettings::amountSelector($config, $page);
        $merchantToken = $this->merchantToken($config);

        if (null === $paymentMethod || null === $amountSelector || null === $merchantToken) {
            return null;
        }

        $localeCode = $this->localeContext->getLocaleCode();

        return [$paymentMethod, new ScalapayWidget(
            $page,
            $merchantToken,
            json_encode([$amountSelector], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            $this->widgetLocale($localeCode),
            $this->amountSeparator($localeCode),
            $this->environment->attribute(),
        )];
    }

    /**
     * The method the widget is displayed under when there is one (checkout), otherwise the enabled
     * Scalapay method of the channel.
     */
    private function scalapayMethod(
        ?PaymentMethodInterface $shownMethod,
        ChannelInterface $channel,
    ): ?PaymentMethodInterface {
        if (null === $shownMethod) {
            return $this->paymentMethodRepository->findOneEnabledByGatewayNameAndChannel(ScalapayGatewayFactory::FACTORY_NAME, $channel);
        }

        return self::isEnabledScalapayMethod($shownMethod) ? $shownMethod : null;
    }

    private static function isEnabledScalapayMethod(PaymentMethodInterface $paymentMethod): bool
    {
        return $paymentMethod->isEnabled() &&
            ScalapayGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName();
    }

    /**
     * The token stored with the PayPlug limits, provided it was fetched in the environment (TEST
     * or LIVE) the gateway is in now.
     *
     * @param array<array-key, mixed> $config
     */
    private function merchantToken(array $config): ?string
    {
        $stored = PproConfig::fromArray($config[PproConfig::CONFIG_KEY] ?? null);

        if (null === $stored || '' === (string) $stored->merchantToken || $stored->live !== (true === ($config['live'] ?? false))) {
            return null;
        }

        return $stored->merchantToken;
    }

    private function widgetLocale(string $localeCode): string
    {
        $language = strtolower(explode('_', str_replace('-', '_', $localeCode))[0]);

        return \in_array($language, self::SUPPORTED_LOCALES, true) ? $language : self::DEFAULT_LOCALE;
    }

    /**
     * The decimal separator of the prices Sylius prints for $localeCode, so the widget reads
     * "1,299.00" and "1 299,00" alike. Left out when it is neither of the two the widget accepts.
     */
    private function amountSeparator(string $localeCode): ?string
    {
        $separator = (new \NumberFormatter($localeCode, \NumberFormatter::CURRENCY))->getSymbol(\NumberFormatter::MONETARY_SEPARATOR_SYMBOL);

        return \in_array($separator, ['.', ','], true) ? $separator : null;
    }
}
