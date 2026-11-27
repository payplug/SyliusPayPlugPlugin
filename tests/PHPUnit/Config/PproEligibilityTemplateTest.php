<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders the admin PPRO block with the Symfony/Sylius Twig helpers stubbed, to check which alerts
 * the stored `payment_method` block turns on. `false` and `[]` are "empty" for Twig's `default`
 * filter, so a stored value must be read with `??` for these alerts to ever show.
 */
final class PproEligibilityTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/admin/payment_method/form/ppro_eligibility.html.twig';

    public function testDisabledByPayPlugAlertShowsWhenPayPlugReportsTheMethodAsNotEnabled(): void
    {
        self::assertStringContainsString('ui.ppro.disabled_by_payplug', $this->render(['enabled' => false]));
    }

    public function testNoDisabledAlertForAnEnabledMethod(): void
    {
        self::assertStringNotContainsString('ui.ppro.disabled_by_payplug', $this->render(['enabled' => true]));
        self::assertStringNotContainsString('ui.ppro.disabled_by_payplug', $this->render([]));
    }

    public function testNoCountryLeftAlertShowsWhenTheMerchantSelectionIsEmpty(): void
    {
        self::assertStringContainsString('ui.ppro.no_country_left', $this->render(['configured_countries' => []]));
    }

    public function testNoCountryLeftAlertIsHiddenOtherwise(): void
    {
        self::assertStringNotContainsString('ui.ppro.no_country_left', $this->render(['configured_countries' => null]));
        self::assertStringNotContainsString('ui.ppro.no_country_left', $this->render(['configured_countries' => ['BE']]));
    }

    /**
     * @param array<string, mixed> $pproBlock
     */
    private function render(array $pproBlock): string
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template);

        $twig = new Environment(new ArrayLoader(['ppro' => $template]));
        foreach (['stimulus_controller', 'stimulus_target', 'sylius_test_html_attribute', 'form_row', 'asset'] as $function) {
            $twig->addFunction(new TwigFunction($function, static fn (): string => '', ['is_safe' => ['html']]));
        }
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFilter(new TwigFilter('sylius_format_money', static fn (int $amount): string => (string) $amount));

        $field = ['vars' => ['disabled' => false]];

        return $twig->render('ppro', [
            'hookable_metadata' => ['context' => ['form' => [
                'gatewayConfig' => ['config' => [
                    'ppro_min_amount' => $field,
                    'ppro_max_amount' => $field,
                    'ppro_countries' => $field,
                ]],
                'vars' => ['data' => ['gatewayConfig' => ['config' => ['payment_method' => $pproBlock]]]],
            ]]],
        ]);
    }
}
