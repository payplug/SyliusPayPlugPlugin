<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Spike;

use PayplugUnifiedCore\Contracts\ITokenCache;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PRE-3469 spike: proof-of-concept implementation of ITokenCache against a PSR-6 cache pool —
 * not shipped code.
 *
 * No friction found: ITokenCache's get/set/delete map 1:1 onto CacheItemPoolInterface's
 * getItem/save/deleteItem, exactly as sketched in the interface's own docblock. Sylius's
 * default `cache.app` pool (Symfony\Component\Cache\Adapter\AdapterInterface) already satisfies
 * CacheItemPoolInterface, so this needs no new infrastructure — it can be constructed with
 * `cache.app` regardless of whether that pool is backed by the filesystem, APCu, or Redis in a
 * given deployment (see PayPlugApiClientFactory for the plugin's existing, narrower
 * Symfony\Contracts\Cache\CacheInterface usage — both contracts sit on the same underlying pool).
 */
final class SyliusTokenCache implements ITokenCache
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
