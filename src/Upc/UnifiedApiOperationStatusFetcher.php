<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class UnifiedApiOperationStatusFetcher implements OperationStatusFetcherInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private ScopedConfigurationRepositoryInterface $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function getOperation(string $operationId, PaymentMethodInterface $method): array
    {
        $configuration = $this->configurationRepository->forPaymentMethod($method);

        $service = new UnifiedApiPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $configuration->getClientId(),
            $configuration->getClientSecret(),
        );

        return $service->getOperation($operationId);
    }
}
