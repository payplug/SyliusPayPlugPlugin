<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway;

use PayPlug\SyliusPayPlugPlugin\Gateway\GatewayCredentials;
use PHPUnit\Framework\TestCase;

final class GatewayCredentialsTest extends TestCase
{
    private const CLIENT = ['client_id' => 'id', 'client_secret' => 'secret'];

    public function testLiveModeNeedsTheLiveClient(): void
    {
        self::assertTrue(GatewayCredentials::isConnected(['live' => true, 'live_client' => self::CLIENT]));
        self::assertFalse(GatewayCredentials::isConnected(['live' => true, 'test_client' => self::CLIENT]));
    }

    public function testTestModeNeedsTheTestClient(): void
    {
        self::assertTrue(GatewayCredentials::isConnected(['live' => false, 'test_client' => self::CLIENT]));
        self::assertTrue(GatewayCredentials::isConnected(['test_client' => self::CLIENT]));
        self::assertFalse(GatewayCredentials::isConnected(['live' => false, 'live_client' => self::CLIENT]));
    }

    /**
     * @dataProvider incompleteConfigs
     */
    public function testIncompleteCredentialsAreNotConnected(array $config): void
    {
        self::assertFalse(GatewayCredentials::isConnected($config));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function incompleteConfigs(): iterable
    {
        yield 'never connected' => [[]];
        yield 'client is null' => [['test_client' => null]];
        yield 'client is a string' => [['test_client' => 'id']];
        yield 'no secret' => [['test_client' => ['client_id' => 'id']]];
        yield 'empty id' => [['test_client' => ['client_id' => '', 'client_secret' => 'secret']]];
        yield 'non-string secret' => [['test_client' => ['client_id' => 'id', 'client_secret' => 12]]];
    }
}
