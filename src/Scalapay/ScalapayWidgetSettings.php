<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Scalapay;

/**
 * Per-page settings of the Scalapay widget, stored in the Scalapay gateway config under
 * `scalapay_widget`, outside the `payment_method` block that each refresh from PayPlug rewrites.
 * A page with no stored settings does not show the widget (the merchant turns it on), with the selector that
 * matches the Sylius 2 default shop theme ready to be used once it is turned on.
 */
final class ScalapayWidgetSettings
{
    public const CONFIG_KEY = 'scalapay_widget';

    public const PAGE_PRODUCT = 'product';

    public const PAGE_CART = 'cart';

    public const PAGE_CHECKOUT = 'checkout';

    public const PAGES = [self::PAGE_PRODUCT, self::PAGE_CART, self::PAGE_CHECKOUT];

    public const DEFAULT_AMOUNT_SELECTORS = [
        self::PAGE_PRODUCT => '[data-live-name-value="sylius_shop:product:summary"] .fs-3',
        self::PAGE_CART => '[data-live-name-value="sylius_shop:cart:summary"] .h5.text-end',
        self::PAGE_CHECKOUT => '#sylius-shop-checkout-summary-order-total',
    ];

    private const MAX_SELECTOR_LENGTH = 255;

    private const SELECTOR_CHARACTERS = '/^[A-Za-z0-9 #.\-_:>+~*()\[\]=^$|,"\']+$/';

    /**
     * @param array<array-key, mixed> $config the whole gateway config
     *
     * @return array<array-key, mixed> $config with the settings of every page that has none
     */
    public static function withDefaults(array $config): array
    {
        $stored = \is_array($config[self::CONFIG_KEY] ?? null) ? $config[self::CONFIG_KEY] : [];

        foreach (self::PAGES as $page) {
            if (!\is_array($stored[$page] ?? null)) {
                $stored[$page] = ['enabled' => false, 'amount_selector' => self::DEFAULT_AMOUNT_SELECTORS[$page]];
            }
        }

        $config[self::CONFIG_KEY] = $stored;

        return $config;
    }

    /**
     * @param array<array-key, mixed> $config the whole gateway config
     */
    public static function isEnabled(array $config, string $page): bool
    {
        return true === (self::pageSettings($config, $page)['enabled'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $config the whole gateway config
     *
     * @return string|null null when the widget is off on that page or its stored selector is not usable
     */
    public static function amountSelector(array $config, string $page): ?string
    {
        if (!self::isEnabled($config, $page)) {
            return null;
        }

        $selector = self::pageSettings($config, $page)['amount_selector'] ?? null;

        return \is_string($selector) && self::isValidAmountSelector($selector) ? $selector : null;
    }

    /**
     * Accepts the CSS selectors a theme needs to point at a price (ids, classes, attributes,
     * combinators, pseudo-classes, selector lists) and nothing that could close the HTML attribute
     * or the JSON array the selector is printed in.
     */
    public static function isValidAmountSelector(string $selector): bool
    {
        return '' !== trim($selector) &&
            \strlen($selector) <= self::MAX_SELECTOR_LENGTH &&
            1 === preg_match(self::SELECTOR_CHARACTERS, $selector) &&
            self::hasBalancedDelimiters($selector);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function pageSettings(array $config, string $page): array
    {
        $pages = self::withDefaults($config)[self::CONFIG_KEY];
        $settings = \is_array($pages) ? ($pages[$page] ?? null) : null;

        return \is_array($settings) ? $settings : [];
    }

    /**
     * Removes the quoted values, then the innermost bracket and parenthesis pairs until none is
     * left: any quote, bracket or parenthesis remaining is unclosed or misplaced.
     */
    private static function hasBalancedDelimiters(string $selector): bool
    {
        $remaining = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $selector);

        do {
            $remaining = (string) preg_replace('/\[[^\[\]()"\']*\]|\([^\[\]()"\']*\)/', '', $remaining, -1, $count);
        } while ($count > 0);

        return 1 !== preg_match('/["\'\[\]()]/', $remaining);
    }
}
