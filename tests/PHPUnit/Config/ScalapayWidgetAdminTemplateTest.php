<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ScalapayWidgetAdminTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/admin/payment_method/form/scalapay_widget.html.twig';

    private const HOOKS_FILE = __DIR__ . '/../../../config/twig_hooks/admin.yaml';

    public function testRendersTheTwoFieldsOfEveryPage(): void
    {
        $html = $this->render(['merchant_token' => 'TESTTOKEN1']);

        foreach (ScalapayWidgetSettings::PAGES as $page) {
            self::assertStringContainsString('[row:scalapay_widget_' . $page . ']', $html);
            self::assertStringContainsString('[row:scalapay_widget_' . $page . '_amount_selector]', $html);
        }
    }

    public function testNoMerchantTokenAlertShowsUntilPayPlugProvidesOne(): void
    {
        self::assertStringContainsString('ui.scalapay_widget.no_merchant_token', $this->render([]));
        self::assertStringContainsString('ui.scalapay_widget.no_merchant_token', $this->render(null));
        self::assertStringNotContainsString('ui.scalapay_widget.no_merchant_token', $this->render(['merchant_token' => 'TESTTOKEN1']));
    }

    public function testTheBlockIsHookedOnTheScalapayCreateAndUpdateScreensOnly(): void
    {
        $hooks = Yaml::parseFile(self::HOOKS_FILE)['sylius_twig_hooks']['hooks'];
        $template = '@PayPlugSyliusPayPlugPlugin/admin/payment_method/form/scalapay_widget.html.twig';

        foreach (['create', 'update'] as $action) {
            foreach (['payplug', 'payplug_oney', 'payplug_bancontact', 'payplug_apple_pay', 'payplug_american_express', 'payplug_scalapay', 'payplug_wero'] as $factoryName) {
                $hookName = sprintf('sylius_admin.payment_method.%s.content.form.sections.gateway_configuration.%s', $action, $factoryName);
                $expected = 'payplug_scalapay' === $factoryName ? $template : null;

                self::assertSame($expected, $hooks[$hookName]['scalapay_widget']['template'] ?? null, $hookName);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $pproBlock
     */
    private function render(?array $pproBlock): string
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template);

        $twig = new Environment(new ArrayLoader(['widget' => $template]));
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('form_row', static fn (array $field): string => '[row:' . $field['name'] . ']', ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));

        $configForm = [];
        foreach (ScalapayWidgetSettings::PAGES as $page) {
            foreach (['scalapay_widget_' . $page, 'scalapay_widget_' . $page . '_amount_selector'] as $name) {
                $configForm[$name] = ['name' => $name];
            }
        }

        $config = null === $pproBlock ? [] : ['payment_method' => $pproBlock];

        return $twig->render('widget', [
            'hookable_metadata' => ['context' => ['form' => [
                'gatewayConfig' => ['config' => $configForm],
                'vars' => ['data' => ['gatewayConfig' => ['config' => $config]]],
            ]]],
        ]);
    }
}
