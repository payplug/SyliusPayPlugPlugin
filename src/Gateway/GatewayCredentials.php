<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway;

/**
 * Whether a gateway config holds the PayPlug client credentials of its active environment (LIVE or
 * TEST), the ones an API client is built from.
 */
final class GatewayCredentials
{
    /**
     * @param array<array-key, mixed> $config
     */
    public static function isConnected(array $config): bool
    {
        $client = true === ($config['live'] ?? false) ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);

        return \is_array($client) && self::isFilled($client['client_id'] ?? null) && self::isFilled($client['client_secret'] ?? null);
    }

    private static function isFilled(mixed $value): bool
    {
        return \is_string($value) && '' !== $value;
    }
}
