<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\PhoneRegionResolver;
use PHPUnit\Framework\TestCase;

final class PhoneRegionResolverTest extends TestCase
{
    /**
     * @dataProvider countryCodeProvider
     *
     * @param list<string> $expectedRegions
     */
    public function testResolve_returnsTheRegionsToReadAPhoneNumberIn(string $countryCode, array $expectedRegions): void
    {
        self::assertSame($expectedRegions, PhoneRegionResolver::resolve($countryCode));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function countryCodeProvider(): iterable
    {
        yield 'metropolitan France' => ['FR', ['FR']];
        yield 'another country' => ['DE', ['DE']];
        yield 'overseas territory' => ['MQ', ['MQ', 'FR']];
        yield 'lowercase overseas territory' => ['mq', ['MQ', 'FR']];
        yield 'Guadeloupe' => ['GP', ['GP', 'FR']];
        yield 'Saint-Barthelemy' => ['BL', ['BL', 'GP', 'MF', 'FR']];
        yield 'Saint-Martin' => ['MF', ['MF', 'GP', 'BL', 'FR']];
    }
}
