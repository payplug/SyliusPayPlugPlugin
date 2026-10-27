<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

final class PproRefreshResult
{
    /**
     * @param bool $skipped the stored limits were recent enough to be kept without asking PayPlug
     */
    public function __construct(
        public readonly bool $refreshed,
        public readonly bool $adjusted = false,
        public readonly bool $skipped = false,
    ) {
    }

    public static function notRefreshed(): self
    {
        return new self(false);
    }

    public static function upToDate(): self
    {
        return new self(true, false, true);
    }
}
