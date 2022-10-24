<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

interface ApplePayCheckerInterface
{
    public function isDeviceReady(): bool;
}
