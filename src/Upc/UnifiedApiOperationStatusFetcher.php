<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiOperationService;

final class UnifiedApiOperationStatusFetcher implements OperationStatusFetcherInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private IConfigurationRepository $configurationRepository,
        private string $unifiedApiPaymentStatusBaseUrl,
    ) {
    }

    public function getOperation(string $operationId): array
    {
        $service = new UnifiedApiOperationService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiPaymentStatusBaseUrl,
            $this->configurationRepository->getClientId(),
            $this->configurationRepository->getClientSecret(),
        );

        return $service->getOperation($operationId);
    }
}
