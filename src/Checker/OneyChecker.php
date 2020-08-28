<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;

final class OneyChecker implements OneyCheckerInterface
{
    private const ONEY_PERMISSION_FIELD = 'can_use_oney';

    /** @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface */
    private $client;

    public function __construct(PayPlugApiClientInterface $oneyClient)
    {
        $this->client = $oneyClient;
    }

    public function isEnabled(): bool
    {
        $permissions = $this->client->getPermissions();

        return (bool) ($permissions[self::ONEY_PERMISSION_FIELD] ?? false);
    }
}
