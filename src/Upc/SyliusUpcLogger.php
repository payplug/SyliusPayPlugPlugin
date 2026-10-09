<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\ILogger;
use Psr\Log\LoggerInterface;

final class SyliusUpcLogger implements ILogger
{
    public function __construct(private LoggerInterface $psrLogger)
    {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->psrLogger->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->psrLogger->info($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->psrLogger->error($message, $context);
    }
}
