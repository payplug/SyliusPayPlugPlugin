<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\PayplugOperation;
use Sylius\Component\Resource\Repository\RepositoryInterface;

interface PayplugOperationRepositoryInterface extends RepositoryInterface
{
    public function findOneByOperationId(string $operationId): ?PayplugOperation;

    public function findOneByOrderId(string $orderId): ?PayplugOperation;
}
