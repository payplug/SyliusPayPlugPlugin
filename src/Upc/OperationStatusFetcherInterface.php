<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;

interface OperationStatusFetcherInterface
{
    /**
     * @return array{status: int, body: string}
     *
     * @throws OperationNotFoundException
     * @throws ApiException
     */
    public function getOperation(string $operationId): array;
}
