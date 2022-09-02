<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\ApiClient;

interface PayPlugApiClientFactoryInterface
{
    public function create(string $factoryName, ?string $key = null): PayPlugApiClientInterface;
}
