<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;

final class UnifiedApiPaymentCreator implements UnifiedApiPaymentCreatorInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private IConfigurationRepository $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function createPayment(PaymentRequestPayload $dto): PaymentOutput
    {
        $service = new UnifiedApiPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $this->configurationRepository->getClientId(),
            $this->configurationRepository->getClientSecret(),
        );

        return $service->createPayment($dto);
    }
}
