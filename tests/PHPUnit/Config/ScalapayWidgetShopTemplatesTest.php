<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the storefront Scalapay templates with the widget functions stubbed. The CCD2 rules of
 * the widget are checked on the output: the v5 module script only, the tag alone with no text,
 * class or style around it, and attributes escaped.
 */
final class ScalapayWidgetShopTemplatesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const SCRIPT_TAG = '<script type="module" src="https://cdn.scalapay.com/widget/v5/js/scalapay-widget.js"></script>';

    private ?ScalapayWidget $widget = null;

    private bool $scriptRequired = false;

    /** @var list<mixed> */
    private array $calls = [];

    public function testScriptIsTheV5ModuleScript(): void
    {
        $this->scriptRequired = true;

        self::assertSame(self::SCRIPT_TAG, trim($this->render('shop/scalapay/script.html.twig')));
    }

    public function testNoScriptWhenNoWidgetCanBeShown(): void
    {
        self::assertSame('', trim($this->render('shop/scalapay/script.html.twig')));
    }

    public function testProductWidgetIsTheBareTagWithItsAttributes(): void
    {
        $this->widget = new ScalapayWidget('product', 'TESTTOKEN1', '["[data-live-name-value=\"sylius_shop:product:summary\"] .fs-3"]', 'fr', ',', 'integration');

        $html = trim($this->render('shop/scalapay/product/widget.html.twig', ['variant' => 'variant-1']));

        self::assertSame(['variant-1'], $this->calls);
        self::assertStringStartsWith('<scalapay-widget', $html);
        self::assertStringEndsWith('></scalapay-widget>', $html);
        self::assertSame([
            'type' => 'product',
            'merchant-token' => 'TESTTOKEN1',
            'amount-selectors' => '["[data-live-name-value=\"sylius_shop:product:summary\"] .fs-3"]',
            'locale' => 'fr',
            'amount-separator' => ',',
            'environment' => 'integration',
        ], $this->attributes($html));
    }

    public function testOptionalAttributesAreLeftOut(): void
    {
        $this->widget = new ScalapayWidget('cart', 'TESTTOKEN1', '["#total"]', 'en', null, null);

        $attributes = $this->attributes($this->render('shop/scalapay/cart/widget.html.twig'));

        self::assertSame(['type', 'merchant-token', 'amount-selectors', 'locale'], array_keys($attributes));
    }

    public function testAttributeValuesAreEscaped(): void
    {
        $this->widget = new ScalapayWidget('cart', 'TOKEN"><b>x</b>', '["a\' onload=\'x"]', 'en', null, null);

        $html = $this->render('shop/scalapay/cart/widget.html.twig');

        self::assertStringNotContainsString('<b>', $html);
        self::assertSame('TOKEN"><b>x</b>', $this->attributes($html)['merchant-token']);
        self::assertSame('["a\' onload=\'x"]', $this->attributes($html)['amount-selectors']);
    }

    /**
     * @dataProvider pageTemplates
     */
    public function testNothingIsRenderedWithoutWidget(string $template): void
    {
        self::assertSame('', trim($this->render($template, ['variant' => null, 'order' => 'order-1', 'factory' => ScalapayGatewayFactory::FACTORY_NAME])));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pageTemplates(): iterable
    {
        yield 'product' => ['shop/scalapay/product/widget.html.twig'];
        yield 'cart' => ['shop/scalapay/cart/widget.html.twig'];
        yield 'checkout' => ['shop/scalapay/checkout/widget.html.twig'];
    }

    public function testCheckoutWidgetIsRenderedInTheScalapayRowForTheOrder(): void
    {
        $this->widget = new ScalapayWidget('checkout', 'TESTTOKEN1', '["#sylius-shop-checkout-summary-order-total"]', 'fr', ',', null);

        $html = $this->render('shop/scalapay/checkout/widget.html.twig', ['order' => 'order-1', 'factory' => ScalapayGatewayFactory::FACTORY_NAME]);

        self::assertSame(['order-1'], $this->calls);
        self::assertSame('checkout', $this->attributes($html)['type']);
    }

    public function testCheckoutWidgetIsNotRenderedInTheRowOfAnotherMethod(): void
    {
        $this->widget = new ScalapayWidget('checkout', 'TESTTOKEN1', '["#total"]', 'fr', ',', null);

        self::assertSame('', trim($this->render('shop/scalapay/checkout/widget.html.twig', ['order' => 'order-1', 'factory' => OneyGatewayFactory::FACTORY_NAME])));
        self::assertSame([], $this->calls);
    }

    public function testTheV5ScriptIsTheOnlyScalapayScriptShipped(): void
    {
        $finder = (new Finder())->files()
            ->in([self::ROOT . '/templates', self::ROOT . '/assets', self::ROOT . '/public', self::ROOT . '/src', self::ROOT . '/config'])
            ->exclude('node_modules')
            ->contains('/scalapay\.com|scalapay-widget-loader|scalapay\.js|scalapay\.min\.js/i');

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRelativePathname();
            self::assertSame(1, substr_count($file->getContents(), 'cdn.scalapay.com'), $file->getRelativePathname());
            self::assertStringContainsString(self::SCRIPT_TAG, $file->getContents());
        }

        self::assertSame(['shop/scalapay/script.html.twig'], $files);
    }

    public function testNoStylesheetTargetsTheWidget(): void
    {
        $finder = (new Finder())->files()
            ->in([self::ROOT . '/assets', self::ROOT . '/public', self::ROOT . '/templates'])
            ->exclude('node_modules')
            ->name(['*.css', '*.scss', '*.less', '*.js'])
            ->contains('scalapay-widget');

        self::assertCount(0, $finder);
    }

    public function testTheWidgetsAreHookedAlongsideOney(): void
    {
        $hooks = Yaml::parseFile(self::ROOT . '/config/twig_hooks/shop.yaml')['sylius_twig_hooks']['hooks'];
        $product = $hooks['sylius_shop.product.show.content.info.summary'];
        $cart = $hooks['sylius_shop.cart.index.content.form.sections.general#right'];

        self::assertSame('@PayPlugSyliusPayPlugPlugin/shop/scalapay/script.html.twig', $hooks['sylius_shop.base.head']['payplug_scalapay_widget_script']['template'] ?? null);
        self::assertSame('@PayPlugSyliusPayPlugPlugin/shop/scalapay/product/widget.html.twig', $product['pay_with_scalapay']['template'] ?? null);
        self::assertSame('@PayPlugSyliusPayPlugPlugin/shop/scalapay/cart/widget.html.twig', $cart['pay_with_scalapay']['template'] ?? null);
        self::assertSame(
            '@PayPlugSyliusPayPlugPlugin/shop/scalapay/checkout/widget.html.twig',
            $hooks['sylius_shop.shared.form.select_payment.payment.choice.' . ScalapayGatewayFactory::FACTORY_NAME]['scalapay_widget']['template'] ?? null,
        );

        // Between the price (Sylius, 200) and the add-to-cart form (Sylius, 100), next to Oney.
        self::assertGreaterThan($product['pay_with_oney']['priority'], $product['pay_with_scalapay']['priority']);
        self::assertLessThan(200, $product['pay_with_scalapay']['priority']);
        // Between the summary (Sylius, 100) and the checkout button (Sylius, 0), next to Oney.
        self::assertGreaterThan($cart['pay_with_oney']['priority'], $cart['pay_with_scalapay']['priority']);
        self::assertLessThan(100, $cart['pay_with_scalapay']['priority']);
    }

    /**
     * The payment details are rendered inside the <label> of the payment card: a widget there would
     * select the payment method when clicked.
     */
    public function testTheCheckoutWidgetIsNotInThePaymentDetails(): void
    {
        $hooks = Yaml::parseFile(self::ROOT . '/config/twig_hooks/shop.yaml')['sylius_twig_hooks']['hooks'];

        $templates = [];
        foreach ($hooks as $hookName => $hookables) {
            if (str_starts_with($hookName, 'sylius_shop.shared.form.select_payment.payment.choice.details')) {
                $templates = [...$templates, ...array_column($hookables, 'template')];
            }
        }

        self::assertNotContains('@PayPlugSyliusPayPlugPlugin/shop/scalapay/checkout/widget.html.twig', $templates);
    }

    /**
     * @param array{variant?: mixed, order?: mixed, factory?: string} $context
     */
    private function render(string $template, array $context = []): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::ROOT . '/templates', 'PayPlugSyliusPayPlugPlugin');
        $twig = new Environment($loader, ['strict_variables' => true]);

        $twig->addFunction(new TwigFunction('payplug_scalapay_widget_script_required', fn (): bool => $this->scriptRequired));
        $twig->addFunction(new TwigFunction('payplug_scalapay_product_widget', $this->widgetFor(...)));
        $twig->addFunction(new TwigFunction('payplug_scalapay_cart_widget', $this->widgetFor(...)));
        $twig->addFunction(new TwigFunction('payplug_scalapay_checkout_widget', $this->widgetFor(...)));

        $hookContext = [];
        if (\array_key_exists('variant', $context)) {
            $hookContext['variant'] = $context['variant'];
        }
        if (\array_key_exists('order', $context)) {
            $hookContext['order'] = $context['order'];
            $hookContext['method'] = ['gatewayConfig' => ['factoryName' => $context['factory'] ?? ScalapayGatewayFactory::FACTORY_NAME]];
        }

        return $twig->render('@PayPlugSyliusPayPlugPlugin/' . $template, ['hookable_metadata' => ['context' => $hookContext]]);
    }

    private function widgetFor(mixed $subject = null): ?ScalapayWidget
    {
        if (null !== $subject) {
            $this->calls[] = $subject;
        }

        return $this->widget;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $element = $document->getElementsByTagName('scalapay-widget')->item(0);
        self::assertNotNull($element);

        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }
}
