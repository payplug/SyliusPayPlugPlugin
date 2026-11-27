<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PproTranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'it'];

    /**
     * @dataProvider pproKeys
     */
    public function testEveryPproKeyExistsInEnFrIt(string $domain, string $key): void
    {
        $placeholders = null;

        foreach (self::LOCALES as $locale) {
            $message = $this->message($domain, $locale, $key);

            self::assertIsString($message, sprintf('%s.%s.yml is missing "%s"', $domain, $locale, $key));
            self::assertNotSame('', trim($message), sprintf('%s.%s.yml has an empty "%s"', $domain, $locale, $key));

            preg_match_all('/%[a-z_]+%/', $message, $matches);
            $found = array_unique($matches[0]);
            sort($found);
            $placeholders ??= $found;
            self::assertSame($placeholders, $found, sprintf('"%s" does not use the same placeholders in %s.%s.yml', $key, $domain, $locale));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pproKeys(): iterable
    {
        $keys = [
            'messages' => [
                'payplug_sylius_payplug_plugin.ui.ppro.amount_title',
                'payplug_sylius_payplug_plugin.ui.ppro.amount_help',
                'payplug_sylius_payplug_plugin.ui.ppro.min_amount',
                'payplug_sylius_payplug_plugin.ui.ppro.max_amount',
                'payplug_sylius_payplug_plugin.ui.ppro.api_limits',
                'payplug_sylius_payplug_plugin.ui.ppro.countries_title',
                'payplug_sylius_payplug_plugin.ui.ppro.countries_help',
                'payplug_sylius_payplug_plugin.ui.ppro.countries_label',
                'payplug_sylius_payplug_plugin.ui.ppro.not_connected',
                'payplug_sylius_payplug_plugin.ui.ppro.disabled_by_payplug',
                'payplug_sylius_payplug_plugin.ui.ppro.no_country_left',
                'payplug_sylius_payplug_plugin.error.ppro_not_available',
            ],
            'validators' => [
                'payplug_sylius_payplug_plugin.ppro.min_below_api_min',
                'payplug_sylius_payplug_plugin.ppro.max_above_api_max',
                'payplug_sylius_payplug_plugin.ppro.min_greater_than_max',
                'payplug_sylius_payplug_plugin.ppro.invalid_amount',
                'payplug_sylius_payplug_plugin.ppro.no_country',
                'payplug_sylius_payplug_plugin.ppro.country_not_allowed',
            ],
            'flashes' => [
                'payplug_sylius_payplug_plugin.ppro.refresh_failed',
                'payplug_sylius_payplug_plugin.ppro.settings_adjusted',
                'payplug_sylius_payplug_plugin.error.ppro_not_available',
            ],
        ];

        foreach ($keys as $domain => $domainKeys) {
            foreach ($domainKeys as $key) {
                yield $domain . ': ' . $key => [$domain, $key];
            }
        }
    }

    /**
     * @dataProvider removedScalapayKeys
     */
    public function testRemovedScalapayKeysAreGone(string $domain, string $key): void
    {
        foreach (self::LOCALES as $locale) {
            self::assertNull($this->message($domain, $locale, $key), sprintf('%s.%s.yml still has "%s"', $domain, $locale, $key));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function removedScalapayKeys(): iterable
    {
        yield 'scalapay min label' => ['messages', 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.min_amount'];
        yield 'scalapay max label' => ['messages', 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.max_amount'];
        yield 'scalapay help' => ['messages', 'payplug_sylius_payplug_plugin.ui.scalapay_gateway_config.amount_help'];
        yield 'scalapay min greater than max' => ['validators', 'payplug_sylius_payplug_plugin.payplug_scalapay.min_amount_greater_than_max'];
        yield 'scalapay out of range' => ['validators', 'payplug_sylius_payplug_plugin.payplug_scalapay.amount_out_of_authorized_range'];
    }

    private function message(string $domain, string $locale, string $key): mixed
    {
        $node = Yaml::parseFile(sprintf('%s/../../../translations/%s.%s.yml', __DIR__, $domain, $locale));

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
