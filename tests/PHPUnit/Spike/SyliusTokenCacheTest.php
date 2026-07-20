<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Spike;

use PayPlug\SyliusPayPlugPlugin\Spike\SyliusTokenCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class SyliusTokenCacheTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;

    private SyliusTokenCache $tokenCache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->tokenCache = new SyliusTokenCache($this->cache);
    }

    public function testGet_hit_returnsStoredValue(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn('jwt-value');
        $this->cache->method('getItem')->with('token-key')->willReturn($item);

        self::assertSame('jwt-value', $this->tokenCache->get('token-key'));
    }

    public function testGet_miss_returnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($item);

        self::assertNull($this->tokenCache->get('token-key'));
    }

    /**
     * PSR-6's CacheItemInterface::get() is typed mixed — defend against a non-string value
     * ever having been stored under this key (e.g. by another caller of the same pool).
     */
    public function testGet_hitWithNonStringValue_returnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(42);
        $this->cache->method('getItem')->willReturn($item);

        self::assertNull($this->tokenCache->get('token-key'));
    }

    public function testSet_storesValueWithTtlAndSaves(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with('jwt-value');
        $item->expects(self::once())->method('expiresAfter')->with(298);
        $this->cache->method('getItem')->with('token-key')->willReturn($item);
        $this->cache->expects(self::once())->method('save')->with($item);

        $this->tokenCache->set('token-key', 'jwt-value', 298);
    }

    public function testDelete_removesItem(): void
    {
        $this->cache->expects(self::once())->method('deleteItem')->with('token-key');

        $this->tokenCache->delete('token-key');
    }
}
