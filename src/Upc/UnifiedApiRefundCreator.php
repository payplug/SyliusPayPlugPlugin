<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class UnifiedApiRefundCreator implements RefundCreatorInterface
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private IConfigurationRepository $configurationRepository,
        private string $unifiedApiBaseUrl,
    ) {
    }

    public function createRefund(
        PaymentMethodInterface $method,
        string $operationId,
        string $orderId,
        ?int $amount = null,
    ): array
    {
        [$accountId, $subMerchantExternalId] = GatewayCredentialsResolver::resolve($method);

        $service = new UnifiedApiPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->unifiedApiBaseUrl,
            $this->configurationRepository->getClientId(),
            $this->configurationRepository->getClientSecret(),
        );

        return $service->createRefund(
            $operationId,
            $accountId,
            $orderId,
            \sprintf('Refund for order %s', $orderId),
            $subMerchantExternalId,
            $amount,
        );
    }
}
