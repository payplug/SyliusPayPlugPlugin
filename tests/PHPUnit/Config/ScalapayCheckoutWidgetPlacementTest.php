<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidget;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\Hook\Normalizer\Name\CompositeNameNormalizer;
use Sylius\TwigHooks\Hook\Normalizer\Prefix\CompositePrefixNormalizer;
use Sylius\TwigHooks\Hook\Normalizer\Prefix\RemoveSectionPartNormalizer;
use Sylius\TwigHooks\Hook\Renderer\HookRenderer;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Sylius\TwigHooks\Hookable\Merger\HookableMerger;
use Sylius\TwigHooks\Hookable\Metadata\HookableMetadataFactory;
use Sylius\TwigHooks\Hookable\Renderer\CompositeHookableRenderer;
use Sylius\TwigHooks\Hookable\Renderer\HookableTemplateRenderer;
use Sylius\TwigHooks\Provider\DefaultConfigurationProvider;
use Sylius\TwigHooks\Provider\DefaultContextProvider;
use Sylius\TwigHooks\Provider\TemplateConfigurationProvider;
use Sylius\TwigHooks\Registry\HookablesRegistry;
use Sylius\TwigHooks\Twig\HooksExtension;
use Sylius\TwigHooks\Twig\Runtime\HooksRuntime;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormView;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders the checkout payment choices through the Twig hooks of Sylius and of the plugin, with the
 * Sylius shop templates, and checks where the checkout widget lands in the markup: a click inside
 * the <label> of a payment card selects that payment method.
 */
final class ScalapayCheckoutWidgetPlacementTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const SYLIUS_SHOP = self::ROOT . '/vendor/sylius/sylius/src/Sylius/Bundle/ShopBundle';

    private const PAYMENT_HOOK = 'sylius_shop.shared.form.select_payment.payment';

    private const HOOKABLE_CONDITION_CHECKER = 'Sylius\TwigHooks\Hookable\Checker\HookableConditionChecker';

    /** @var list<mixed> */
    private array $widgetCalls = [];

    public function testTheWidgetFollowsTheScalapayCardOutsideItsLabel(): void
    {
        $xpath = $this->renderChoices(['offline', ScalapayGatewayFactory::FACTORY_NAME, BancontactGatewayFactory::FACTORY_NAME]);

        self::assertSame(1, $xpath->query('//scalapay-widget')?->length);
        self::assertSame(0, $xpath->query('//label//scalapay-widget')?->length, 'The widget is inside a payment card label.');
        self::assertSame(0, $xpath->query('//*[contains(@style, "display")]//scalapay-widget')?->length, 'The widget is inside the hidden payment details.');

        $card = $xpath->query('//scalapay-widget/preceding-sibling::*[1]')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $card);
        self::assertTrue($card->hasAttribute('data-test-payment-item'));
        self::assertSame(1, $xpath->query('.//input[@id="method_1"]', $card)?->length);

        $next = $xpath->query('//scalapay-widget/following-sibling::*[1]')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $next);
        self::assertSame(1, $xpath->query('.//input[@id="method_2"]', $next)?->length);

        self::assertSame(['order-1'], $this->widgetCalls);
    }

    public function testTheWidgetIsOnlyRenderedForTheScalapayMethod(): void
    {
        $xpath = $this->renderChoices(['offline', BancontactGatewayFactory::FACTORY_NAME]);

        self::assertSame(0, $xpath->query('//scalapay-widget')?->length);
        self::assertSame(2, $xpath->query('//*[@data-test-payment-item]')?->length);
        self::assertSame([], $this->widgetCalls);
    }

    public function testEachPaymentCardIsStillRenderedWithItsMethodDetails(): void
    {
        $xpath = $this->renderChoices([ScalapayGatewayFactory::FACTORY_NAME, 'offline']);

        self::assertSame(2, $xpath->query('//*[@data-test-payment-item]/label[@class="card-body"]')?->length);
        self::assertSame(1, $xpath->query('//label//*[@data-payment-input-id="method_0"]//*[@data-logo-input="method_0"]')?->length);
        self::assertSame(1, $xpath->query('//label//*[@data-payment-input-id="method_1"]')?->length);
    }

    /**
     * @param list<string> $factoryNames
     */
    private function renderChoices(array $factoryNames): \DOMXPath
    {
        $form = new FormView();
        $methodField = new FormView($form);
        $form->children['method'] = $methodField;

        $choices = [];
        foreach ($factoryNames as $index => $factoryName) {
            $choice = new FormView($methodField);
            $choice->vars['id'] = 'method_' . $index;
            $methodField->children[(string) $index] = $choice;
            $choices[$index] = new ChoiceView(
                ['code' => 'method_' . $index, 'description' => null, 'gatewayConfig' => ['factoryName' => $factoryName]],
                'method_' . $index,
                'Method ' . $index,
            );
        }
        $methodField->vars['choices'] = $choices;

        $html = $this->twig()
            ->createTemplate(\sprintf("{%% hook '%s' with { form: form, order: order, index: 1 } %%}", self::PAYMENT_HOOK))
            ->render(['form' => $form, 'order' => 'order-1']);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::ROOT . '/templates', 'PayPlugSyliusPayPlugPlugin');
        $loader->addPath(self::SYLIUS_SHOP . '/templates', 'SyliusShop');
        $twig = new Environment($loader, ['strict_variables' => true]);

        $prefixNormalizer = new CompositePrefixNormalizer([new RemoveSectionPartNormalizer('#')]);
        $hookRendererArguments = [
            new HookablesRegistry($this->hookables(), new HookableMerger()),
            new CompositeHookableRenderer([new HookableTemplateRenderer($twig, new TemplateConfigurationProvider(new ExpressionLanguage()))]),
            new DefaultContextProvider(),
            new DefaultConfigurationProvider(),
            new HookableMetadataFactory($prefixNormalizer),
        ];
        // sylius/twig-hooks 0.14 (Sylius 2.3) also passes a hookable condition checker to the HookRenderer.
        if (class_exists(self::HOOKABLE_CONDITION_CHECKER)) {
            $hookRendererArguments[] = new (self::HOOKABLE_CONDITION_CHECKER)(new ExpressionLanguage());
        }
        $hookRenderer = new HookRenderer(...$hookRendererArguments);
        $runtime = new HooksRuntime($hookRenderer, new CompositeNameNormalizer([]), $prefixNormalizer, true);

        $twig->addExtension(new HooksExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([HooksRuntime::class => static fn (): HooksRuntime => $runtime]));

        $html = ['is_safe' => ['html']];
        $twig->addFunction(new TwigFunction('form_errors', static fn (): string => '', $html));
        $twig->addFunction(new TwigFunction(
            'form_widget',
            static fn (FormView $field): string => \sprintf('<input type="radio" name="method" id="%s">', $field->vars['id']),
            $html,
        ));
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (string $name): string => 'data-test-' . $name, $html));
        $twig->addFunction(new TwigFunction('sylius_test_form_attribute', static fn (): array => []));
        $twig->addFunction(new TwigFunction(
            'stimulus_controller',
            static fn (string $name, array $values = []): Markup => new Markup(
                isset($values['inputId']) ? \sprintf('data-logo-input="%s"', $values['inputId']) : 'data-controller',
                'UTF-8',
            ),
        ));
        $twig->addFunction(new TwigFunction('stimulus_target', static fn (): string => 'data-target', $html));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addFunction(new TwigFunction('payplug_scalapay_checkout_widget', function (mixed $order): ScalapayWidget {
            $this->widgetCalls[] = $order;

            return new ScalapayWidget('checkout', 'TESTTOKEN1', '["#sylius-shop-checkout-summary-order-total"]', 'fr', ',', null);
        }));
        $twig->addFilter(new TwigFilter('trans', static fn (string $message): string => $message));

        return $twig;
    }

    /**
     * The hookables of the payment choice hooks, the plugin configuration overriding Sylius's as in
     * the application.
     *
     * @return list<HookableTemplate>
     */
    private function hookables(): array
    {
        $hooks = array_replace_recursive(
            Yaml::parseFile(self::SYLIUS_SHOP . '/Resources/config/app/twig_hooks/shared/form/select_payment.yaml')['sylius_twig_hooks']['hooks'],
            Yaml::parseFile(self::ROOT . '/config/twig_hooks/shop.yaml')['sylius_twig_hooks']['hooks'],
        );

        $hookables = [];
        foreach ($hooks as $hookName => $hookablesOfHook) {
            if (!str_starts_with($hookName, self::PAYMENT_HOOK)) {
                continue;
            }
            foreach ($hookablesOfHook as $name => $hookable) {
                $hookables[] = new HookableTemplate($hookName, $name, $hookable['template'], priority: $hookable['priority'] ?? null);
            }
        }

        return $hookables;
    }
}
