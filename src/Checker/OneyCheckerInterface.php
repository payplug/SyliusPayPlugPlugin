<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

interface OneyCheckerInterface
{
    public function isEnabled(): bool;
}
