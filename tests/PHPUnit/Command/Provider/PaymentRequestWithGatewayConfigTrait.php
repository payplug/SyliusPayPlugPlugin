<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Command\Provider;

use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Shared by the Capture/Notify/StatusPaymentRequestCommandProvider tests: builds a mocked
 * PaymentRequest for a payment method with (or without) a given gateway factory name/config.
 */
trait PaymentRequestWithGatewayConfigTrait
{
    /**
     * @param array{factoryName: string, config: array<string, mixed>}|null $gatewayConfig
     * @param array<string, mixed> $details
     */
    private function paymentRequestWithConfig(
        ?array $gatewayConfig,
        array $details = [],
    ): PaymentRequestInterface&MockObject
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        if (null !== $gatewayConfig) {
            $config = $this->createMock(GatewayConfigInterface::class);
            $config->method('getFactoryName')->willReturn($gatewayConfig['factoryName']);
            $config->method('getConfig')->willReturn($gatewayConfig['config']);
            $method->method('getGatewayConfig')->willReturn($config);
        }

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getDetails')->willReturn($details);

        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getPayment')->willReturn($payment);
        $paymentRequest->method('getId')->willReturn('1');

        return $paymentRequest;
    }
}
