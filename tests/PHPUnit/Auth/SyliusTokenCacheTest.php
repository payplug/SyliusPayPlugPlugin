<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\SyliusTokenCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class SyliusTokenCacheTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $pool;

    private SyliusTokenCache $cache;

    protected function setUp(): void
    {
        $this->pool = $this->createMock(CacheItemPoolInterface::class);
        $this->cache = new SyliusTokenCache($this->pool);
    }

    // -------------------------------------------------------------------------
    // get() — cache hit / miss
    // -------------------------------------------------------------------------

    public function testGet_onCacheHit_returnsTheStoredValue(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn('cached-jwt');

        $this->pool->method('getItem')->with('upc_oauth_token_client_abc')->willReturn($item);

        self::assertSame('cached-jwt', $this->cache->get('upc_oauth_token:client_abc'));
    }

    public function testGet_onCacheMiss_returnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $this->pool->method('getItem')->willReturn($item);

        self::assertNull($this->cache->get('upc_oauth_token:client_abc'));
    }

    // -------------------------------------------------------------------------
    // set() — stores the value with the given TTL
    // -------------------------------------------------------------------------

    public function testSet_storesValueAndTtlThenSavesTheItem(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with('fresh-jwt');
        $item->expects(self::once())->method('expiresAfter')->with(240);

        $this->pool->method('getItem')->with('upc_oauth_token_client_abc')->willReturn($item);
        $this->pool->expects(self::once())->method('save')->with($item);

        $this->cache->set('upc_oauth_token:client_abc', 'fresh-jwt', 240);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDelete_removesTheSanitizedKeyFromThePool(): void
    {
        $this->pool->expects(self::once())->method('deleteItem')->with('upc_oauth_token_client_abc');

        $this->cache->delete('upc_oauth_token:client_abc');
    }

    // -------------------------------------------------------------------------
    // Key sanitization — PSR-6 reserved characters must never reach the pool
    // -------------------------------------------------------------------------

    /**
     * Symfony's cache component rejects keys containing any of "{}()/\@:" with an
     * InvalidArgumentException. TokenManager's own key format ("upc_oauth_token:{clientId}")
     * contains a colon, so this is a real, not hypothetical, input.
     *
     * @dataProvider reservedCharacterKeys
     */
    public function testSanitizeKey_replacesEveryPsr6ReservedCharacter(
        string $rawKey,
        string $expectedSanitizedKey,
    ): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $this->pool->expects(self::once())->method('getItem')->with($expectedSanitizedKey)->willReturn($item);

        $this->cache->get($rawKey);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function reservedCharacterKeys(): iterable
    {
        yield 'colon (TokenManager\'s real format)' => ['upc_oauth_token:client_abc', 'upc_oauth_token_client_abc'];
        yield 'curly braces' => ['a{b}c', 'a_b_c'];
        yield 'parentheses' => ['a(b)c', 'a_b_c'];
        yield 'slash' => ['a/b', 'a_b'];
        yield 'backslash' => ['a\\b', 'a_b'];
        yield 'at sign' => ['a@b', 'a_b'];
        yield 'all reserved characters combined' => ['{}()/\\@:', '________'];
        yield 'no reserved characters' => ['plain_key_123', 'plain_key_123'];
    }
}
