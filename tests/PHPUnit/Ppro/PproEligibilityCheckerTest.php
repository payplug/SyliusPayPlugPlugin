<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Ppro;

use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproEligibilityChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;

final class PproEligibilityCheckerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private PproEligibilityChecker $checker;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->checker = new PproEligibilityChecker($this->logger);
    }

    public function testNoStoredBlockIsFailOpenForEur(): void
    {
        $this->logger->expects(self::never())->method('warning');

        self::assertTrue($this->checker->isEligible($this->paymentMethod([]), 1, 'EUR', 'US'));
        self::assertTrue($this->checker->isEligible($this->paymentMethod([]), 99999999, 'EUR', null));
    }

    public function testPaymentMethodWithoutGatewayConfigIsFailOpen(): void
    {
        self::assertTrue($this->checker->isEligible(new PaymentMethod(), 1000, 'EUR', 'FR'));
    }

    public function testMalformedBlockIsFailOpenAndLogged(): void
    {
        $this->logger->expects(self::once())->method('warning');

        $paymentMethod = $this->paymentMethod(['payment_method' => ['default_amount' => ['min' => 'abc']]]);

        self::assertTrue($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'FR'));
    }

    public function testNonEurCurrencyIsHidden(): void
    {
        self::assertFalse($this->checker->isEligible($this->paymentMethod([]), 1000, 'USD', null));
        self::assertFalse($this->checker->isEligible($this->paymentMethod(['payment_method' => $this->block()]), 1000, 'USD', 'BE'));
    }

    public function testDelegatesToPproConfig(): void
    {
        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block()]);

        self::assertTrue($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'FR'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 29, 'EUR', 'BE'));
        self::assertFalse($this->checker->isEligible($paymentMethod, 2000001, 'EUR', 'BE'));
    }

    public function testEmptyEffectiveRangeIsHiddenAndLogged(): void
    {
        $this->logger->expects(self::once())->method('warning');

        $paymentMethod = $this->paymentMethod(['payment_method' => $this->block([
            'default_amount' => ['min' => 30, 'max' => 1000],
            'configured_amount' => ['min' => 5000, 'max' => 9000],
        ])]);

        self::assertFalse($this->checker->isEligible($paymentMethod, 1000, 'EUR', 'BE'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'name' => 'wero',
            'enabled' => true,
            'allowed_countries' => ['BE', 'DE'],
            'default_amount' => ['min' => 30, 'max' => 2000000],
            'configured_amount' => ['min' => 30, 'max' => 2000000],
            'configured_countries' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(WeroGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setGatewayName(WeroGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('wero');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
