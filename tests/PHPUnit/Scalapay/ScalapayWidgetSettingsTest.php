<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Scalapay;

use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetSettings;
use PHPUnit\Framework\TestCase;

final class ScalapayWidgetSettingsTest extends TestCase
{
    /**
     * @dataProvider pages
     */
    public function testEveryPageIsOffWhenNothingIsStored(string $page): void
    {
        self::assertFalse(ScalapayWidgetSettings::isEnabled([], $page));
        self::assertNull(ScalapayWidgetSettings::amountSelector([], $page));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pages(): iterable
    {
        foreach (ScalapayWidgetSettings::PAGES as $page) {
            yield $page => [$page];
        }
    }

    public function testDefaultSelectorsAreValid(): void
    {
        foreach (ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS as $selector) {
            self::assertTrue(ScalapayWidgetSettings::isValidAmountSelector($selector), $selector);
        }
    }

    public function testStoredSettingsAreRead(): void
    {
        $config = ['scalapay_widget' => [
            'product' => ['enabled' => true, 'amount_selector' => '#my-price'],
            'cart' => ['enabled' => false, 'amount_selector' => '#my-total'],
        ]];

        self::assertSame('#my-price', ScalapayWidgetSettings::amountSelector($config, 'product'));
        self::assertFalse(ScalapayWidgetSettings::isEnabled($config, 'cart'));
        self::assertNull(ScalapayWidgetSettings::amountSelector($config, 'cart'));
        self::assertFalse(ScalapayWidgetSettings::isEnabled($config, 'checkout'));
        self::assertNull(ScalapayWidgetSettings::amountSelector($config, 'checkout'));
    }

    /**
     * @dataProvider unusableStoredSelectors
     */
    public function testAnUnusableStoredSelectorShowsNoWidget(mixed $selector): void
    {
        $config = ['scalapay_widget' => ['product' => ['enabled' => true, 'amount_selector' => $selector]]];

        self::assertNull(ScalapayWidgetSettings::amountSelector($config, 'product'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unusableStoredSelectors(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not a string' => [['#price']];
        yield 'markup' => ['"><script>alert(1)</script>'];
    }

    public function testANonBooleanEnabledFlagIsOff(): void
    {
        $config = ['scalapay_widget' => ['product' => ['enabled' => 'yes', 'amount_selector' => '#price']]];

        self::assertFalse(ScalapayWidgetSettings::isEnabled($config, 'product'));
    }

    public function testAMalformedBlockFallsBackToTheDefaultsWhichAreOff(): void
    {
        self::assertFalse(ScalapayWidgetSettings::isEnabled(['scalapay_widget' => 'garbage'], 'cart'));
        self::assertNull(ScalapayWidgetSettings::amountSelector(['scalapay_widget' => 'garbage'], 'cart'));
    }

    public function testWithDefaultsFillsTheMissingPagesOnly(): void
    {
        $config = ['live' => true, 'scalapay_widget' => ['cart' => ['enabled' => true, 'amount_selector' => '#my-total']]];

        $filled = ScalapayWidgetSettings::withDefaults($config);

        self::assertTrue($filled['live']);
        self::assertSame(['enabled' => true, 'amount_selector' => '#my-total'], $filled['scalapay_widget']['cart']);
        self::assertSame(['enabled' => false, 'amount_selector' => ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS['product']], $filled['scalapay_widget']['product']);
        self::assertSame(['enabled' => false, 'amount_selector' => ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS['checkout']], $filled['scalapay_widget']['checkout']);
    }

    /**
     * @dataProvider validSelectors
     */
    public function testValidSelectors(string $selector): void
    {
        self::assertTrue(ScalapayWidgetSettings::isValidAmountSelector($selector));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validSelectors(): iterable
    {
        yield 'id' => ['#product-price'];
        yield 'descendant classes' => ['.price .sale'];
        yield 'child combinator' => ['div.summary > span.amount'];
        yield 'attribute with double quotes' => ['[data-live-name-value="sylius_shop:product:summary"] .fs-3'];
        yield 'attribute with single quotes' => ["[data-role='price']"];
        yield 'selector list' => ['.price .sale, .price .regular'];
        yield 'pseudo-class' => ['.price:not(.old)'];
    }

    /**
     * @dataProvider invalidSelectors
     */
    public function testInvalidSelectors(string $selector): void
    {
        self::assertFalse(ScalapayWidgetSettings::isValidAmountSelector($selector));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidSelectors(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'markup' => ['<script>'];
        yield 'closing the attribute' => ["'><img src=x>"];
        yield 'css block' => ['a{color:red}'];
        yield 'semicolon' => ['.a;'];
        yield 'unclosed bracket' => ['[data-price'];
        yield 'unbalanced parenthesis' => ['.a:not(.b'];
        yield 'unclosed quote' => ['[data-x="y]'];
        yield 'new line' => [".a\n.b"];
        yield 'backslash' => ['\\61'];
        yield 'comment' => ['/* x */ .a'];
        yield 'too long' => ['#' . str_repeat('a', 255)];
    }
}
