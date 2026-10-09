<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HostedFieldsSdkUrlConfigurationTest extends TestCase
{
    private const SERVICES_FILE = __DIR__ . '/../../../config/services.yaml';

    private const TEMPLATE_FILE = __DIR__ . '/../../../templates/shop/hosted_fields/index.html.twig';

    public function testTheParameterIsOverridableByEnvAndBoundToTheTwigExtension(): void
    {
        $config = Yaml::parseFile(self::SERVICES_FILE, Yaml::PARSE_CUSTOM_TAGS);

        self::assertSame(
            '%env(default:payplug.hosted_fields_sdk_url.default:PAYPLUG_HOSTED_FIELDS_SDK_URL)%',
            $config['parameters']['payplug.hosted_fields_sdk_url'],
        );
        self::assertSame(
            '%payplug.hosted_fields_sdk_url%',
            $config['services']['PayPlug\SyliusPayPlugPlugin\\']['bind']['$hostedFieldsSdkUrl'],
        );
    }

    public function testTheDefaultIsTheProductionSdkPinnedToV220(): void
    {
        $config = Yaml::parseFile(self::SERVICES_FILE, Yaml::PARSE_CUSTOM_TAGS);

        self::assertSame(
            'https://js.dalenys.com/hosted-fields/v2.2.0/hosted-fields.min.js',
            $config['parameters']['payplug.hosted_fields_sdk_url.default'],
        );
    }

    public function testTheTemplateLoadsTheSdkFromTheConfiguredUrlOnly(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE_FILE);

        self::assertStringNotContainsString('dlns.io', $template);
        self::assertStringNotContainsString('staging', $template);
        self::assertStringContainsString("src=\"{{ payplug_hosted_fields_sdk_url()|e('html_attr') }}\"", $template);
    }
}
