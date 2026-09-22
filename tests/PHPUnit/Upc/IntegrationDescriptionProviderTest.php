<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\IntegrationDescriptionProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationDescriptionProviderTest extends TestCase
{
    public function testBuild_returnsAllThreeComponentsInTheExpectedFormat(): void
    {
        $description = IntegrationDescriptionProvider::build();

        self::assertMatchesRegularExpression(
            '/^Sylius-\S+\/SyliusPayPlugPlugin-\S+\/UPC-\S+$/',
            $description,
        );
    }

    public function testBuild_neverReturnsAnEmptyOrTruncatedComponent(): void
    {
        $description = IntegrationDescriptionProvider::build();

        [$sylius, $plugin, $upc] = \explode('/', $description);

        self::assertStringStartsWith('Sylius-', $sylius);
        self::assertNotSame('Sylius-', $sylius);
        self::assertStringStartsWith('SyliusPayPlugPlugin-', $plugin);
        self::assertNotSame('SyliusPayPlugPlugin-', $plugin);
        self::assertStringStartsWith('UPC-', $upc);
        self::assertNotSame('UPC-', $upc);
    }
}
