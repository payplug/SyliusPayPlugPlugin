<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Scalapay;

use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * ScalapayWidgetEnvironment compares the OAuth URL with the production platform of PayPlug, which
 * the plugin also declares as the default of `payplug.oauth_base_url`. If the two drift apart, the
 * shops of production would get `environment="integration"` and an empty widget.
 */
final class ScalapayWidgetEnvironmentDefaultTest extends TestCase
{
    public function testTheDefaultOauthUrlOfTheServicesIsTheProductionPlatform(): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/services.yaml', Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        $default = $services['parameters']['payplug.oauth_base_url.default'] ?? null;

        self::assertIsString($default);
        self::assertNull((new ScalapayWidgetEnvironment($default))->attribute());
    }
}
