<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\TokenCache;

use PayplugUnifiedCore\Contracts\ITokenCache;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PRE-3469: real implementation of ITokenCache, targeting the OAuth JWT access-token cache
 * (per the ticket's clarified wording, and the interface's own docblock) — not saved-card
 * storage, which is a permanent Doctrine entity (Card) with no cache involved.
 *
 * get/set/delete map 1:1 onto CacheItemPoolInterface's getItem/save/deleteItem. Sylius's
 * default `cache.app` pool (Symfony\Component\Cache\Adapter\AdapterInterface, autowired here
 * via FrameworkBundle's built-in CacheItemPoolInterface -> cache.app alias) already satisfies
 * CacheItemPoolInterface, regardless of whether that pool is backed by the filesystem, APCu, or
 * Redis in a given deployment. This class is validated by a real-infrastructure integration
 * test (see SpikeIntegrationTest) rather than wired into a live request path: the one existing
 * production caller of an OAuth token cache, PayPlugApiClientFactory::getTokenForGatewayConfig(),
 * gates authentication for every PayPlug gateway — replacing its inline caching logic here would
 * carry more regression risk than this ticket's validation goal justifies.
 */
final class PayplugTokenCache implements ITokenCache
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function get(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    public function delete(string $key): void
    {
        $this->cache->deleteItem($key);
    }
}
