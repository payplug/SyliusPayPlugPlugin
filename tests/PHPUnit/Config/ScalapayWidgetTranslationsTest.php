<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ScalapayWidgetTranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'it'];

    private const KEYS = [
        'messages' => [
            'ui.scalapay_widget.title',
            'ui.scalapay_widget.help',
            'ui.scalapay_widget.product',
            'ui.scalapay_widget.cart',
            'ui.scalapay_widget.checkout',
            'ui.scalapay_widget.amount_selector',
            'ui.scalapay_widget.amount_selector_help',
            'ui.scalapay_widget.no_merchant_token',
        ],
        'validators' => [
            'scalapay_widget.amount_selector_required',
            'scalapay_widget.invalid_amount_selector',
        ],
    ];

    public function testEveryWidgetKeyExistsInEveryLocaleWithTheSamePlaceholders(): void
    {
        foreach (self::KEYS as $domain => $keys) {
            foreach ($keys as $key) {
                $placeholders = null;

                foreach (self::LOCALES as $locale) {
                    $message = $this->message($domain, $locale, $key);

                    self::assertIsString($message, sprintf('%s.%s.yml is missing "%s"', $domain, $locale, $key));
                    self::assertNotSame('', trim($message));

                    preg_match_all('/%[a-z_]+%/', $message, $matches);
                    $placeholders ??= $matches[0];
                    self::assertSame($placeholders, $matches[0], sprintf('"%s" placeholders differ in %s.%s.yml', $key, $domain, $locale));
                }
            }
        }
    }

    public function testTheSelectorHelpShowsTheDefaultSelector(): void
    {
        self::assertStringContainsString('%default%', (string) $this->message('messages', 'en', 'ui.scalapay_widget.amount_selector_help'));
    }

    private function message(string $domain, string $locale, string $key): mixed
    {
        $value = Yaml::parseFile(sprintf('%s/../../../translations/%s.%s.yml', __DIR__, $domain, $locale))['payplug_sylius_payplug_plugin'] ?? null;

        foreach (explode('.', $key) as $part) {
            $value = \is_array($value) ? ($value[$part] ?? null) : null;
        }

        return $value;
    }
}
