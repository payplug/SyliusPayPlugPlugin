<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Output\CancellationOutput;
use PayplugUnifiedCore\Output\CaptureOutput;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class UnifiedApiAuthorizationOperator implements AuthorizationOperatorInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private ScopedConfigurationRepositoryInterface $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function capture(
        PaymentMethodInterface $method,
        string $paymentId,
        string $orderId,
        ?int $amount,
        ?string $currency = null,
        int $sequence = 1,
    ): CaptureOutput
    {
        return $this->createService($method)->capturePayment(
            $paymentId,
            GatewayCredentialsResolver::resolve($method),
            $orderId,
            \sprintf('Capture #%d for order %s', $sequence, $orderId),
            $amount,
            null,
            $currency,
        );
    }

    public function cancel(
        PaymentMethodInterface $method,
        string $paymentId,
        string $orderId,
        ?int $amount,
        int $sequence = 1,
        ?string $currency = null,
    ): CancellationOutput
    {
        return $this->createService($method)->cancelPayment(
            $paymentId,
            GatewayCredentialsResolver::resolve($method),
            $orderId,
            \sprintf('Cancellation #%d for order %s', $sequence, $orderId),
            $amount,
            null,
            $currency,
        );
    }

    private function createService(PaymentMethodInterface $method): UnifiedApiPaymentService
    {
        $configuration = $this->configurationRepository->forPaymentMethod($method);

        return new UnifiedApiPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $configuration->getClientId(),
            $configuration->getClientSecret(),
        );
    }
}
