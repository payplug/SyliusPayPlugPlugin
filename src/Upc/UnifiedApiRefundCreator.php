<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class UnifiedApiRefundCreator implements RefundCreatorInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private ScopedConfigurationRepositoryInterface $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function createRefund(
        PaymentMethodInterface $method,
        string $operationId,
        string $orderId,
        ?int $amount = null,
        ?string $currency = null,
    ): array {
        $accountId = GatewayCredentialsResolver::resolve($method);
        $configuration = $this->configurationRepository->forPaymentMethod($method);

        $service = new UnifiedApiPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $configuration->getClientId(),
            $configuration->getClientSecret(),
        );

        return $service->createRefund(
            $operationId,
            $accountId,
            $orderId,
            \sprintf('Refund for order %s', $orderId),
            null,
            $amount,
            $currency,
        );
    }
}
