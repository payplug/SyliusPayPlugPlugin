<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Builds a per-merchant UnifiedApiHostedPaymentService from a payment method's gateway config,
 * the same way PayPlugApiClientFactory::createForPaymentMethod() builds a per-merchant
 * PayPlugApiClient. account_id is NOT part of the service's own constructor
 * (UnifiedApiHostedPaymentService inherits AbstractUnifiedApiService's plain 5-argument one) — it
 * is per-call data that belongs on CommonFieldsDto, built by whoever calls createHostedPayment()
 * (see UhfHostedFieldsPaymentProcessor). getAccountId() exposes it alongside
 * createForPaymentMethod() so that caller doesn't have to re-read the gateway config itself,
 * sharing this class's single "all three credentials must be present" validation.
 */
final class UnifiedApiHostedPaymentServiceFactory
{
    public function __construct(
        private IUnifiedApiHttpClient $httpClient,
        private TokenManager $tokenManager,
        private string $payplugOauthBaseUrl,
    ) {
    }

    public function createForPaymentMethod(PaymentMethodInterface $paymentMethod): UnifiedApiHostedPaymentService
    {
        $clientConfig = $this->getValidatedClientConfig($paymentMethod);

        return new UnifiedApiHostedPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->payplugOauthBaseUrl,
            $clientConfig['client_id'],
            $clientConfig['client_secret'],
        );
    }

    public function getAccountId(PaymentMethodInterface $paymentMethod): string
    {
        return $this->getValidatedClientConfig($paymentMethod)['account_id'];
    }

    /** @return array{client_id: string, client_secret: string, account_id: string} */
    private function getValidatedClientConfig(PaymentMethodInterface $paymentMethod): array
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig() ?? throw new \LogicException('Gateway config not found');
        $rawClientConfig = $this->getClientConfig($gatewayConfig);

        $clientId = $rawClientConfig['client_id'] ?? '';
        $clientSecret = $rawClientConfig['client_secret'] ?? '';
        $accountId = $rawClientConfig['account_id'] ?? '';

        if ('' === $clientId || '' === $clientSecret || '' === $accountId) {
            throw new GatewayConfigurationException('No client config found for ' . UhfGatewayFactory::FACTORY_NAME . '. Please renew your credentials in the PayPlug plugin configuration.');
        }

        return ['client_id' => $clientId, 'client_secret' => $clientSecret, 'account_id' => $accountId];
    }

    /** @return array<string, string> */
    private function getClientConfig(GatewayConfigInterface $gatewayConfig): array
    {
        $config = $gatewayConfig->getConfig();
        $isLive = true === ($config['live'] ?? false);
        $rawClientConfig = $isLive ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);
        if (!\is_array($rawClientConfig)) {
            return [];
        }

        /** @var array<string, string> $clientConfig */
        $clientConfig = $rawClientConfig;

        return $clientConfig;
    }
}
