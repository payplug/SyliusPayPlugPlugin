<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

final class PproRefreshResult
{
    public function __construct(
        public readonly bool $refreshed,
        public readonly bool $adjusted = false,
    ) {
    }

    public static function notRefreshed(): self
    {
        return new self(false);
    }
}
