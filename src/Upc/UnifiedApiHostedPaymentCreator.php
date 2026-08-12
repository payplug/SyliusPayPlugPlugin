<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Output\HostedPaymentOutput;
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;

final class UnifiedApiHostedPaymentCreator implements HostedPaymentCreatorInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private IConfigurationRepository $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function createHostedPayment(HostedFieldDto $dto): HostedPaymentOutput
    {
        $service = new UnifiedApiHostedPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $this->configurationRepository->getClientId(),
            $this->configurationRepository->getClientSecret(),
        );

        return $service->createHostedPayment($dto);
    }
}
