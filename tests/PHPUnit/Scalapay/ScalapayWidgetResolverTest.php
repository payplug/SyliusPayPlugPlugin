<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Scalapay;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidget;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetEnvironment;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetResolver;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ScalapayWidgetResolverTest extends TestCase
{
    private const NOW = '2026-11-19T09:00:00+00:00';

    private const PRODUCTION_PLATFORM = 'https://api.payplug.com';

    private const QA_PLATFORM = 'https://api-qa.payplug.com';

    private PaymentMethodRepositoryInterface&MockObject $paymentMethodRepository;

    private ChannelContextInterface&MockObject $channelContext;

    private CurrencyContextInterface&MockObject $currencyContext;

    private LocaleContextInterface&MockObject $localeContext;

    private CartContextInterface&MockObject $cartContext;

    private ProductVariantPricesCalculatorInterface&MockObject $pricesCalculator;

    private ScalapayWidgetResolver $resolver;

    private Channel $channel;

    private PaymentMethod $lastMethod;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->channelContext = $this->createMock(ChannelContextInterface::class);
        $this->currencyContext = $this->createMock(CurrencyContextInterface::class);
        $this->localeContext = $this->createMock(LocaleContextInterface::class);
        $this->cartContext = $this->createMock(CartContextInterface::class);
        $this->pricesCalculator = $this->createMock(ProductVariantPricesCalculatorInterface::class);

        $this->channel = $this->channel('WEB_FR');
        $this->channelContext->method('getChannel')->willReturnCallback(fn (): ChannelInterface => $this->channel);
        $this->currencyContext->method('getCurrencyCode')->willReturn('EUR');
        $this->localeContext->method('getLocaleCode')->willReturn('fr_FR');

        $this->resolver = new ScalapayWidgetResolver(
            $this->paymentMethodRepository,
            $this->channelContext,
            $this->currencyContext,
            $this->localeContext,
            $this->cartContext,
            $this->pricesCalculator,
            new PproEligibilityChecker(new NullLogger(), new MockClock(self::NOW)),
            new ScalapayWidgetEnvironment(self::PRODUCTION_PLATFORM),
        );
    }

    public function testProductWidgetCarriesEveryAttribute(): void
    {
        $this->givenScalapay(self::config(['live' => true], ['live' => true]));
        $this->pricesCalculator->method('calculate')->willReturn(12990);

        $widget = $this->resolver->forProduct(new ProductVariant());

        self::assertEquals(new ScalapayWidget(
            'product',
            'TESTTOKEN1',
            '["[data-live-name-value=\"sylius_shop:product:summary\"] .fs-3"]',
            'fr',
            ',',
            null,
        ), $widget);
    }

    public function testTheVariantPriceIsCalculatedForTheCurrentChannel(): void
    {
        $this->givenScalapay(self::config());
        $this->pricesCalculator->expects(self::once())->method('calculate')
            ->with(self::isInstanceOf(ProductVariant::class), ['channel' => $this->channel])
            ->willReturn(12990);

        self::assertNotNull($this->resolver->forProduct(new ProductVariant()));
    }

    /**
     * The Scalapay environment of the token PayPlug returns depends on the PayPlug platform, never on
     * the TEST or LIVE mode of the gateway.
     *
     * @dataProvider platformsAndModes
     */
    public function testRenderedWidgetTargetsTheScalapayEnvironmentOfThePayPlugPlatform(
        string $oauthBaseUrl,
        bool $live,
        ?string $environment,
    ): void {
        $resolver = new ScalapayWidgetResolver(
            $this->paymentMethodRepository,
            $this->channelContext,
            $this->currencyContext,
            $this->localeContext,
            $this->cartContext,
            $this->pricesCalculator,
            new PproEligibilityChecker(new NullLogger(), new MockClock(self::NOW)),
            new ScalapayWidgetEnvironment($oauthBaseUrl),
        );
        $this->givenScalapay(self::config(['live' => $live]));
        $this->pricesCalculator->method('calculate')->willReturn(12990);

        $attributes = $this->renderedProductWidgetAttributes($resolver);

        self::assertSame('TESTTOKEN1', $attributes['merchant-token'] ?? null);
        if (null === $environment) {
            self::assertArrayNotHasKey('environment', $attributes);
        } else {
            self::assertSame($environment, $attributes['environment'] ?? null);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string|null}>
     */
    public static function platformsAndModes(): iterable
    {
        yield 'production platform, LIVE gateway' => [self::PRODUCTION_PLATFORM, true, null];
        yield 'production platform, TEST gateway' => [self::PRODUCTION_PLATFORM, false, null];
        yield 'QA platform, LIVE gateway' => [self::QA_PLATFORM, true, 'integration'];
        yield 'QA platform, TEST gateway' => [self::QA_PLATFORM, false, 'integration'];
        yield 'production platform written with a trailing slash and in uppercase' => ['HTTPS://API.PAYPLUG.COM/', false, null];
    }

    public function testNoProductWidgetWithoutVariant(): void
    {
        $this->givenScalapay(self::config());

        self::assertNull($this->resolver->forProduct(null));
    }

    public function testNoWidgetWhenTheChannelHasNoEnabledScalapayMethod(): void
    {
        $this->paymentMethodRepository->method('findOneEnabledByGatewayNameAndChannel')->willReturn(null);

        self::assertNull($this->resolver->forProduct(new ProductVariant()));
        self::assertFalse($this->resolver->isScriptRequired('sylius_shop_checkout_select_payment'));
    }

    /**
     * @dataProvider configsWithoutUsableToken
     *
     * @param array<string, mixed> $config
     */
    public function testNoWidgetWithoutAUsableMerchantToken(array $config): void
    {
        $this->givenScalapay($config);

        self::assertNull($this->resolver->forCheckout($this->order(10000), $this->lastMethod));
        self::assertFalse($this->resolver->isScriptRequired('sylius_shop_checkout_select_payment'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function configsWithoutUsableToken(): iterable
    {
        yield 'no PayPlug block' => [['live' => false]];
        yield 'block without token' => [self::config([], ['merchant_token' => null])];
        yield 'empty token' => [self::config([], ['merchant_token' => ''])];
        yield 'token fetched for LIVE while the gateway is in TEST' => [self::config(['live' => false], ['live' => true])];
        yield 'token fetched for TEST while the gateway is in LIVE' => [self::config(['live' => true], ['live' => false])];
        yield 'malformed block' => [['live' => false, 'payment_method' => ['merchant_token' => 42]]];
    }

    public function testCheckoutWidgetUsesTheMethodOfTheCardNotASecondLookup(): void
    {
        $shown = $this->paymentMethod(self::config([], ['merchant_token' => 'CARDTOKEN']));
        $this->paymentMethodRepository->expects(self::never())->method('findOneEnabledByGatewayNameAndChannel');

        self::assertSame('CARDTOKEN', $this->resolver->forCheckout($this->order(10000, 'FR'), $shown)?->merchantToken);
    }

    public function testNoCheckoutWidgetForAMethodThatIsNotAnEnabledScalapayMethod(): void
    {
        $disabled = $this->paymentMethod(self::config());
        $disabled->setEnabled(false);
        self::assertNull($this->resolver->forCheckout($this->order(10000, 'FR'), $disabled));

        $otherGateway = $this->paymentMethod(self::config());
        $otherGateway->getGatewayConfig()->setFactoryName('payplug');
        self::assertNull($this->resolver->forCheckout($this->order(10000, 'FR'), $otherGateway));
    }

    public function testNoWidgetOnAnyPageUntilTheMerchantTurnsItOn(): void
    {
        $config = self::config();
        unset($config['scalapay_widget']);
        $this->givenScalapay($config);
        $this->cartContext->method('getCart')->willReturn($this->order(10000));

        self::assertNull($this->resolver->forProduct(new ProductVariant()));
        self::assertNull($this->resolver->forCart());
        self::assertNull($this->resolver->forCheckout($this->order(10000), $this->lastMethod));
        foreach (['sylius_shop_product_show', 'sylius_shop_cart_summary', 'sylius_shop_checkout_select_payment'] as $route) {
            self::assertFalse($this->resolver->isScriptRequired($route), $route);
        }
    }

    public function testNoWidgetOnAPageTurnedOffInTheSettings(): void
    {
        $this->givenScalapay(self::config(['scalapay_widget' => ['checkout' => ['enabled' => false, 'amount_selector' => null]]]));
        $this->cartContext->method('getCart')->willReturn($this->order(10000));

        self::assertNull($this->resolver->forCheckout($this->order(10000), $this->lastMethod));
        self::assertNotNull($this->resolver->forCart());
        self::assertFalse($this->resolver->isScriptRequired('sylius_shop_checkout_select_payment'));
        self::assertTrue($this->resolver->isScriptRequired('sylius_shop_cart_summary'));
    }

    public function testStoredSelectorIsJsonEncoded(): void
    {
        $this->givenScalapay(self::config(['scalapay_widget' => ['checkout' => ['enabled' => true, 'amount_selector' => "[data-role='total'] > span"]]]));

        self::assertSame('["[data-role=\'total\'] > span"]', $this->resolver->forCheckout($this->order(10000), $this->lastMethod)?->amountSelectors);
    }

    public function testNoWidgetWhenTheStoredSelectorIsNotValid(): void
    {
        $this->givenScalapay(self::config(['scalapay_widget' => ['checkout' => ['enabled' => true, 'amount_selector' => '"><script>']]]));

        self::assertNull($this->resolver->forCheckout($this->order(10000), $this->lastMethod));
    }

    public function testNoWidgetWhenPricesAreDisplayedInAnotherCurrency(): void
    {
        $currencyContext = $this->createMock(CurrencyContextInterface::class);
        $currencyContext->method('getCurrencyCode')->willReturn('USD');
        $resolver = new ScalapayWidgetResolver(
            $this->paymentMethodRepository,
            $this->channelContext,
            $currencyContext,
            $this->localeContext,
            $this->cartContext,
            $this->pricesCalculator,
            new PproEligibilityChecker(new NullLogger(), new MockClock(self::NOW)),
            new ScalapayWidgetEnvironment(self::PRODUCTION_PLATFORM),
        );
        $this->givenScalapay(self::config());

        self::assertNull($resolver->forCheckout($this->order(10000), $this->lastMethod));
        self::assertFalse($resolver->isScriptRequired('sylius_shop_checkout_select_payment'));
    }

    /**
     * Euro prices on such a channel are converted from the base currency, which amounts are compared
     * and paid in.
     */
    public function testNoWidgetOnAChannelWhoseBaseCurrencyIsNotTheEuro(): void
    {
        $this->channel = $this->channel('WEB_US', 'USD');
        $this->givenScalapay(self::config());
        $this->pricesCalculator->method('calculate')->willReturn(12990);
        $this->cartContext->method('getCart')->willReturn($this->order(10000, null, OrderInterface::STATE_CART, 'USD'));

        self::assertNull($this->resolver->forProduct(new ProductVariant()));
        self::assertNull($this->resolver->forCart());
        self::assertNull($this->resolver->forCheckout($this->order(10000, 'FR', OrderInterface::STATE_CART, 'USD'), $this->lastMethod));
        foreach (array_keys(ScalapayWidgetResolver::PAGE_BY_ROUTE) as $route) {
            self::assertFalse($this->resolver->isScriptRequired($route), $route);
        }
    }

    /**
     * @dataProvider amountsAroundTheRange
     */
    public function testProductWidgetFollowsTheEffectiveAmountRange(int $amount, bool $shown): void
    {
        $this->givenScalapay(self::config([], ['configured_amount' => ['min' => 1000, 'max' => 150000]]));
        $this->pricesCalculator->method('calculate')->willReturn($amount);

        self::assertSame($shown, null !== $this->resolver->forProduct(new ProductVariant()));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function amountsAroundTheRange(): iterable
    {
        yield 'zero' => [0, false];
        yield 'one cent below the merchant minimum' => [999, false];
        yield 'merchant minimum' => [1000, true];
        yield 'merchant maximum' => [150000, true];
        yield 'one cent above the merchant maximum' => [150001, false];
    }

    public function testCartWidgetIsHiddenForABillingCountryScalapayDoesNotServe(): void
    {
        $this->givenScalapay(self::config());
        $this->cartContext->method('getCart')->willReturn($this->order(10000, 'BE'));

        self::assertNull($this->resolver->forCart());
    }

    public function testCartWidgetWithoutBillingAddressSkipsTheCountryRule(): void
    {
        $this->givenScalapay(self::config());
        $this->cartContext->method('getCart')->willReturn($this->order(10000));

        self::assertSame('cart', $this->resolver->forCart()?->type);
    }

    public function testCartWidgetIsHiddenOutsideTheAmountRange(): void
    {
        $this->givenScalapay(self::config());
        $this->cartContext->method('getCart')->willReturn($this->order(499, 'FR'));

        self::assertNull($this->resolver->forCart());
    }

    public function testNoWidgetWhilePayPlugReportsScalapayAsNotEnabled(): void
    {
        $this->givenScalapay(self::config([], ['enabled' => false]));

        self::assertNull($this->resolver->forCheckout($this->order(10000), $this->lastMethod));
    }

    public function testCheckoutWidgetIsOnlyForAnOrderStillInCheckout(): void
    {
        $this->givenScalapay(self::config());

        self::assertSame('checkout', $this->resolver->forCheckout($this->order(10000, 'FR'), $this->lastMethod)?->type);
        self::assertNull($this->resolver->forCheckout($this->order(10000, 'FR', OrderInterface::STATE_NEW), $this->lastMethod));
    }

    /**
     * @dataProvider locales
     */
    public function testLocaleAndAmountSeparatorFollowTheShopLocale(
        string $localeCode,
        string $locale,
        string $separator,
    ): void {
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn($localeCode);
        $resolver = new ScalapayWidgetResolver(
            $this->paymentMethodRepository,
            $this->channelContext,
            $this->currencyContext,
            $localeContext,
            $this->cartContext,
            $this->pricesCalculator,
            new PproEligibilityChecker(new NullLogger(), new MockClock(self::NOW)),
            new ScalapayWidgetEnvironment(self::PRODUCTION_PLATFORM),
        );
        $this->givenScalapay(self::config());

        $widget = $resolver->forCheckout($this->order(10000), $this->lastMethod);

        self::assertSame($locale, $widget?->locale);
        self::assertSame($separator, $widget->amountSeparator);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function locales(): iterable
    {
        yield 'French' => ['fr_FR', 'fr', ','];
        yield 'English (US)' => ['en_US', 'en', '.'];
        yield 'Italian' => ['it_IT', 'it', ','];
        yield 'German' => ['de_DE', 'de', ','];
        yield 'Spanish' => ['es_ES', 'es', ','];
        yield 'Portuguese' => ['pt_PT', 'pt', ','];
        yield 'language only' => ['fr', 'fr', ','];
        yield 'unsupported language falls back to English' => ['nl_NL', 'en', ','];
        yield 'Swiss German keeps its own decimal separator' => ['de_CH', 'de', '.'];
    }

    public function testEachChannelGetsTheTokenOfItsOwnScalapayMethod(): void
    {
        $byChannel = [
            'WEB_FR' => $this->paymentMethod(self::config([], ['merchant_token' => 'TOKENFR1'])),
            'WEB_IT' => $this->paymentMethod(self::config([], ['merchant_token' => 'TOKENIT1'])),
        ];
        $this->paymentMethodRepository->method('findOneEnabledByGatewayNameAndChannel')
            ->willReturnCallback(static fn (string $factory, ChannelInterface $channel): ?PaymentMethodInterface => ScalapayGatewayFactory::FACTORY_NAME === $factory ? ($byChannel[$channel->getCode()] ?? null) : null);

        self::assertSame('TOKENFR1', $this->resolver->forCheckout($this->order(10000), $byChannel['WEB_FR'])?->merchantToken);

        $this->channel = $this->channel('WEB_IT');
        self::assertSame('TOKENIT1', $this->resolver->forCheckout($this->order(10000), $byChannel['WEB_IT'])?->merchantToken);

        $this->channel = $this->channel('WEB_BE');
        self::assertNull($this->resolver->forProduct(new ProductVariant()));
    }

    /**
     * @dataProvider scriptRoutes
     */
    public function testScriptIsRequiredOnTheWidgetPagesOnly(string $route, bool $required): void
    {
        $this->givenScalapay(self::config());

        self::assertSame($required, $this->resolver->isScriptRequired($route));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function scriptRoutes(): iterable
    {
        yield 'product page' => ['sylius_shop_product_show', true];
        yield 'cart page' => ['sylius_shop_cart_summary', true];
        yield 'checkout payment step' => ['sylius_shop_checkout_select_payment', true];
        yield 'home page' => ['sylius_shop_homepage', false];
        yield 'order page' => ['sylius_shop_order_show', false];
        yield 'no route' => ['', false];
    }

    /**
     * @return array<string, string>
     */
    private function renderedProductWidgetAttributes(ScalapayWidgetResolver $resolver): array
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../../templates', 'PayPlugSyliusPayPlugPlugin');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('payplug_scalapay_product_widget', static fn (): ?ScalapayWidget => $resolver->forProduct(new ProductVariant())));

        $html = $twig->render(
            '@PayPlugSyliusPayPlugPlugin/shop/scalapay/product/widget.html.twig',
            ['hookable_metadata' => ['context' => ['variant' => 'variant-1']]],
        );

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

    /**
     * @param array<string, mixed> $config
     */
    private function givenScalapay(array $config): void
    {
        $this->paymentMethodRepository->method('findOneEnabledByGatewayNameAndChannel')
            ->with(ScalapayGatewayFactory::FACTORY_NAME, self::isInstanceOf(ChannelInterface::class))
            ->willReturn($this->paymentMethod($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(ScalapayGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setGatewayName(ScalapayGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('scalapay');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        $this->lastMethod = $paymentMethod;

        return $paymentMethod;
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $blockOverrides
     *
     * @return array<string, mixed>
     */
    private static function config(array $overrides = [], array $blockOverrides = []): array
    {
        $live = (bool) ($overrides['live'] ?? false);

        // The widget is off on every page until the merchant turns it on: these tests switch the pages
        // on, except the ones the test sets itself.
        $widget = \is_array($overrides['scalapay_widget'] ?? null) ? $overrides['scalapay_widget'] : [];
        foreach (ScalapayWidgetSettings::PAGES as $page) {
            $widget[$page] ??= ['enabled' => true, 'amount_selector' => ScalapayWidgetSettings::DEFAULT_AMOUNT_SELECTORS[$page]];
        }
        $overrides['scalapay_widget'] = $widget;

        return array_merge([
            'live' => $live,
            'payment_method' => array_merge([
                'name' => 'scalapay',
                'enabled' => true,
                'allowed_countries' => ['FR', 'IT'],
                'default_amount' => ['min' => 500, 'max' => 200000],
                'configured_amount' => ['min' => null, 'max' => null],
                'configured_countries' => null,
                'live' => $live,
                'refreshed_at' => self::NOW,
                'merchant_token' => 'TESTTOKEN1',
            ], $blockOverrides),
        ], $overrides);
    }

    private function channel(string $code, string $baseCurrencyCode = 'EUR'): Channel
    {
        $currency = new Currency();
        $currency->setCode($baseCurrencyCode);

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    private function order(
        int $total,
        ?string $billingCountry = null,
        string $state = OrderInterface::STATE_CART,
        string $currencyCode = 'EUR',
    ): OrderInterface&MockObject {
        $address = null;
        if (null !== $billingCountry) {
            $address = new Address();
            $address->setCountryCode($billingCountry);
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getTotal')->willReturn($total);
        $order->method('getCurrencyCode')->willReturn($currencyCode);
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getState')->willReturn($state);

        return $order;
    }
}
