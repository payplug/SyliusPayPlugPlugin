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
    public function testResolve_withAccountIdConfigured_returnsIt(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        self::assertSame('acct_123', GatewayCredentialsResolver::resolve($method));
    }

    /**
     * The submerchant is no longer configurable here — it belongs to the EUR MID configurations,
     * not to the multi-currency ones this flow targets — so a config left over from before the
     * field was removed must resolve exactly like one without it rather than throwing.
     */
    public function testResolve_withALeftoverSubmerchantIdInConfig_ignoresIt(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_IDENTIFIER => 'acct_123',
            'hfSubMerchantId' => 'submerchant_123',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        self::assertSame('acct_123', GatewayCredentialsResolver::resolve($method));
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
        $gatewayConfig->method('getConfig')->willReturn([]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(\LogicException::class);

        GatewayCredentialsResolver::resolve($method);
    }

    public function testResolve_withBlankAccountId_throws(): void
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            PayPlugGatewayFactory::HF_IDENTIFIER => '',
        ]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $this->expectException(\LogicException::class);

        GatewayCredentialsResolver::resolve($method);
    }
}
