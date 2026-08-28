<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\GatewayCredentialsResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class GatewayCredentialsResolverTest extends TestCase
{
    public function testResolve_withBothConfigured_returnsThem(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'submerchant_123',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        self::assertSame(['acct_123', 'submerchant_123'], GatewayCredentialsResolver::resolve($method));
    }

    public function testResolve_withNoGatewayConfig_throws(): void
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn(null);

        $this->expectException(\LogicException::class);

        GatewayCredentialsResolver::resolve($method);
    }

    public function testResolve_withMissingAccountId_throws(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_SUB_MERCHANT_ID => 'submerchant_123',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(\LogicException::class);

        GatewayCredentialsResolver::resolve($method);
    }

    public function testResolve_withMissingSubmerchantId_throws(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(\LogicException::class);

        GatewayCredentialsResolver::resolve($method);
    }
}
