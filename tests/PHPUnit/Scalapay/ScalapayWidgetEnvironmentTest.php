<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Scalapay;

use PayPlug\SyliusPayPlugPlugin\Scalapay\ScalapayWidgetEnvironment;
use PHPUnit\Framework\TestCase;

final class ScalapayWidgetEnvironmentTest extends TestCase
{
    /**
     * @dataProvider platforms
     */
    public function testEnvironmentFollowsThePayPlugPlatform(string $oauthBaseUrl, ?string $environment): void
    {
        self::assertSame($environment, (new ScalapayWidgetEnvironment($oauthBaseUrl))->attribute());
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'production' => ['https://api.payplug.com', null];
        yield 'production with a trailing slash' => ['https://api.payplug.com/', null];
        yield 'production in uppercase' => ['HTTPS://API.PAYPLUG.COM', null];
        yield 'production in mixed case with a trailing slash' => ['Https://Api.PayPlug.com/', null];
        yield 'production with surrounding spaces' => [' https://api.payplug.com ', null];
        yield 'QA platform' => ['https://api-qa.payplug.com', 'integration'];
        yield 'another platform' => ['https://oauth.example.test', 'integration'];
        yield 'production host in plain http' => ['http://api.payplug.com', 'integration'];
        yield 'host that only starts like production' => ['https://api.payplug.com.example.test', 'integration'];
        yield 'production host as a subdomain' => ['https://qa.api.payplug.com', 'integration'];
        yield 'empty' => ['', 'integration'];
        yield 'not a URL' => ['api.payplug.com', 'integration'];
    }
}
