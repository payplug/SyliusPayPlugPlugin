<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ConfigurationRepository;

use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use PayplugUnifiedCore\Exceptions\ApiException;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

/**
 * PRE-3469: real implementation of IConfigurationRepository against Sylius's
 * GatewayConfigInterface.
 *
 * IConfigurationRepository assumes one flat set of credentials, but Sylius scopes gateway
 * config per PaymentMethod *and* per live/test mode (`config['live_client']` vs
 * `config['test_client']`, selected by `config['live']`, exactly as PayPlugApiClientFactory
 * already does). So a single PayplugConfigurationRepository instance has to be constructed per
 * GatewayConfigInterface (i.e. per PaymentMethod) rather than shared as one repository-wide
 * service — a factory, not a singleton. Not blocking, but worth flagging if the Unified API
 * client this feeds ever assumes one repository == one merchant.
 *
 * getPublicKeyId()/getPublicKeyValue() default to an empty string rather than throwing: unlike
 * client_id/client_secret, no production code writes public_key_id/public_key_value yet
 * (Hosted Fields isn't built) — requiring them would make the contract unimplementable until a
 * future ticket adds that writer.
 *
 * Sylius already ships an (experimental) GatewayConfigEncrypter that transparently encrypts the
 * whole `getConfig()` array at rest (Sylius\Component\Payment\Encryption) — if wired up,
 * CLIENT_SECRET benefits from that for free. What this class must still guarantee on its own is
 * that a *decrypted* secret never leaks into a log line or exception message, which is why
 * requireString() below only ever interpolates the config *key name*, never its value.
 */
final class PayplugConfigurationRepository implements IConfigurationRepository
{
    public function __construct(private readonly GatewayConfigInterface $gatewayConfig)
    {
    }

    public function get(string $key): ?string
    {
        $client = $this->activeClientConfig();
        $value = $client[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $config = $this->gatewayConfig->getConfig();
        $scope = $this->activeScope($config);
        $client = $config[$scope] ?? [];
        if (!\is_array($client)) {
            $client = [];
        }
        $client[$key] = $value;
        $config[$scope] = $client;

        // Persisting $config is the caller's responsibility (Doctrine flush), same as every
        // other GatewayConfigInterface mutation in this plugin (see UnifiedAuthenticationController).
        $this->gatewayConfig->setConfig($config);
    }

    public function getClientId(): string
    {
        return $this->requireString('client_id');
    }

    public function getClientSecret(): string
    {
        return $this->requireString('client_secret');
    }

    public function getPublicKeyId(): string
    {
        return $this->get('public_key_id') ?? '';
    }

    public function getPublicKeyValue(): string
    {
        return $this->get('public_key_value') ?? '';
    }

    private function requireString(string $key): string
    {
        $value = $this->get($key);
        if (null === $value || '' === $value) {
            // Never interpolate the resolved *value* here, only the key name and factory name.
            throw new ApiException(\sprintf(
                'Missing "%s" in gateway configuration "%s".',
                $key,
                $this->gatewayConfig->getFactoryName() ?? 'unknown',
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function activeClientConfig(): array
    {
        $config = $this->gatewayConfig->getConfig();
        $client = $config[$this->activeScope($config)] ?? [];
        if (!\is_array($client)) {
            return [];
        }

        /** @var array<string, mixed> $typedClient */
        $typedClient = $client;

        return $typedClient;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function activeScope(array $config): string
    {
        return true === ($config['live'] ?? false) ? 'live_client' : 'test_client';
    }
}
