<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

use PayplugUnifiedCore\Contracts\ITokenCache;
use Psr\Cache\CacheItemPoolInterface;

final class SyliusTokenCache implements ITokenCache
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function get(string $key): ?string
    {
        $item = $this->cache->getItem($this->sanitizeKey($key));

        if (!$item->isHit()) {
            return null;
        }

        /** @var string $value */
        $value = $item->get();

        return $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($this->sanitizeKey($key));
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    public function delete(string $key): void
    {
        $this->cache->deleteItem($this->sanitizeKey($key));
    }

    // PSR-6 rejects "{}()/\@:" in cache keys; TokenManager's keys contain ":".
    private function sanitizeKey(string $key): string
    {
        return (string) preg_replace('/[{}()\/\\\\@:]/', '_', $key);
    }
}
