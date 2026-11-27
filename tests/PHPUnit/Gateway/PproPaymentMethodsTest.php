<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway;

use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PHPUnit\Framework\TestCase;

final class PproPaymentMethodsTest extends TestCase
{
    public function testBancontactWeroAndScalapayAreRegistered(): void
    {
        self::assertSame('bancontact', PproPaymentMethods::apiKey(BancontactGatewayFactory::FACTORY_NAME));
        self::assertSame('wero', PproPaymentMethods::apiKey(WeroGatewayFactory::FACTORY_NAME));
        self::assertSame('scalapay', PproPaymentMethods::apiKey(ScalapayGatewayFactory::FACTORY_NAME));

        self::assertTrue(PproPaymentMethods::isPpro(BancontactGatewayFactory::FACTORY_NAME));
        self::assertTrue(PproPaymentMethods::isPpro(WeroGatewayFactory::FACTORY_NAME));
        self::assertTrue(PproPaymentMethods::isPpro(ScalapayGatewayFactory::FACTORY_NAME));
    }

    /**
     * @dataProvider nonPproFactories
     */
    public function testNonPproFactoriesAreNotRegistered(?string $factoryName): void
    {
        self::assertFalse(PproPaymentMethods::isPpro($factoryName));
        self::assertNull(PproPaymentMethods::apiKey($factoryName));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function nonPproFactories(): iterable
    {
        yield 'card' => [PayPlugGatewayFactory::FACTORY_NAME];
        yield 'oney' => [OneyGatewayFactory::FACTORY_NAME];
        yield 'apple pay' => [ApplePayGatewayFactory::FACTORY_NAME];
        yield 'american express' => [AmericanExpressGatewayFactory::FACTORY_NAME];
        yield 'null' => [null];
        yield 'unknown' => ['payplug_satispay'];
    }
}
