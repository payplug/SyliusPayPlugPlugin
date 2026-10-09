<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\SyliusUpcLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyliusUpcLoggerTest extends TestCase
{
    private LoggerInterface&MockObject $psrLogger;

    private SyliusUpcLogger $logger;

    protected function setUp(): void
    {
        $this->psrLogger = $this->createMock(LoggerInterface::class);
        $this->logger = new SyliusUpcLogger($this->psrLogger);
    }

    public function testDebug_delegatesToThePsrLogger(): void
    {
        $this->psrLogger->expects(self::once())->method('debug')->with('a message', ['key' => 'value']);

        $this->logger->debug('a message', ['key' => 'value']);
    }

    public function testInfo_delegatesToThePsrLogger(): void
    {
        $this->psrLogger->expects(self::once())->method('info')->with('a message', []);

        $this->logger->info('a message');
    }

    public function testError_delegatesToThePsrLogger(): void
    {
        $this->psrLogger->expects(self::once())->method('error')->with('a message', ['key' => 'value']);

        $this->logger->error('a message', ['key' => 'value']);
    }
}
