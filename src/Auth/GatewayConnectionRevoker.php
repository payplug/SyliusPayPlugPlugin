<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayplugUnifiedCore\Contracts\ITokenCache;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Disconnects one gateway config from its PayPlug account: the opposite of the OAuth callback.
 *
 * Deliberately scoped to a single payment method rather than to a factory name. Since PRE-3628 a
 * merchant may hold several gateway configs of the same factory, one per channel, each connected
 * to its own account — logging one out must leave the others authenticated.
 *
 * The gateway is disabled as part of the same unit of work: it no longer has credentials to
 * authenticate with, so leaving it enabled would offer shoppers a payment method whose every API
 * call is going to fail. Note the side effect on PRE-3629's per-channel uniqueness rule, which only
 * counts *enabled* gateways: logging out releases this gateway's channels for another config to
 * claim.
 *
 * @see \PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth\UnifiedAuthenticationController::oauthCallback()
 *      the writer of everything cleared here
 */
final class GatewayConnectionRevoker
{
    /**
     * Mirrors TokenManager::CACHE_KEY_PREFIX, which is private to UPC and has no public accessor.
     * Duplicated rather than skipped: the cached access token is a live credential at rest, and
     * leaving it behind would keep it usable for the rest of its TTL after the merchant asked to
     * disconnect. Pinned by GatewayConnectionRevokerTest; the proper fix is a `forget()` on UPC's
     * TokenManager.
     */
    private const TOKEN_CACHE_KEY_PREFIX = 'upc_oauth_token:';

    /** Client credentials minted per environment by the OAuth callback. */
    private const CLIENT_CREDENTIAL_KEYS = ['live_client', 'test_client'];

    /** Everything the OAuth callback writes, all of it account-bound. */
    private const CONNECTION_KEYS = ['live_client', 'test_client', 'account_email'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ITokenCache $tokenCache,
    ) {
    }

    /**
     * @throws \LogicException if $paymentMethod has no gateway config, so there is no connection to revoke
     */
    public function revoke(PaymentMethodInterface $paymentMethod): void
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig() ?? throw new \LogicException(
            'The payment method has no gateway config, so no PayPlug connection can be revoked for it.',
        );

        $config = $gatewayConfig->getConfig();

        // Before the keys are dropped: the client id is the only handle on the cache entry.
        foreach (self::CLIENT_CREDENTIAL_KEYS as $clientKey) {
            $this->forgetCachedToken($config[$clientKey] ?? null);
        }

        foreach (self::CONNECTION_KEYS as $key) {
            unset($config[$key]);
        }

        // The Hosted Fields account id identifies the account that was just disconnected, so it is
        // stale the moment the merchant reconnects to a different one. Cleared only where it is
        // actually in use — on any other gateway, or on a CB gateway in redirected/integrated mode,
        // it is an inert leftover the merchant may still want on their next login.
        if (PayPlugGatewayFactory::isHostedFieldsConfig($gatewayConfig)) {
            unset($config[PayPlugGatewayFactory::HF_IDENTIFIER]);
        }

        $gatewayConfig->setConfig($config);
        $paymentMethod->disable();

        $this->entityManager->flush();
    }

    private function forgetCachedToken(mixed $clientCredentials): void
    {
        $clientId = \is_array($clientCredentials) ? ($clientCredentials['client_id'] ?? null) : null;

        if (!\is_string($clientId) || '' === $clientId) {
            return;
        }

        $this->tokenCache->delete(self::TOKEN_CACHE_KEY_PREFIX . $clientId);
    }
}
