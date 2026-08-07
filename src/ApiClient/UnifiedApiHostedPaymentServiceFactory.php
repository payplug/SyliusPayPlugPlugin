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
 * PayPlugApiClient: clientId/clientSecret/accountId are plain constructor arguments (not sourced
 * from IConfigurationRepository), since account_id identifies the Unified API processing account
 * and has no relationship to the OAuth2 clientId/clientSecret pair (see
 * UnifiedApiHostedPaymentService's own docblock).
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
        $gatewayConfig = $paymentMethod->getGatewayConfig() ?? throw new \LogicException('Gateway config not found');
        $clientConfig = $this->getClientConfig($gatewayConfig);

        $clientId = $clientConfig['client_id'] ?? '';
        $clientSecret = $clientConfig['client_secret'] ?? '';
        $accountId = $clientConfig['account_id'] ?? '';

        if ('' === $clientId || '' === $clientSecret || '' === $accountId) {
            throw new GatewayConfigurationException('No client config found for ' . UhfGatewayFactory::FACTORY_NAME . '. Please renew your credentials in the PayPlug plugin configuration.');
        }

        return new UnifiedApiHostedPaymentService(
            $this->httpClient,
            $this->tokenManager,
            $this->payplugOauthBaseUrl,
            $clientId,
            $clientSecret,
            $accountId,
        );
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
