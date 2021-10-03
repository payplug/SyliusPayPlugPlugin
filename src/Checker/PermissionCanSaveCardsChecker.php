<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;

final class PermissionCanSaveCardsChecker
{
    private const CAN_SAVE_CARDS_PERMISSION_FIELD = 'can_save_cards';

    /** @var PayPlugApiClientInterface */
    private $client;

    public function __construct(PayPlugApiClientInterface $apiClient)
    {
        $this->client = $apiClient;
    }

    public function isEnabled(): bool
    {
        $permissions = $this->client->getPermissions();

        return (bool) ($permissions[self::CAN_SAVE_CARDS_PERMISSION_FIELD] ?? false);
    }
}
