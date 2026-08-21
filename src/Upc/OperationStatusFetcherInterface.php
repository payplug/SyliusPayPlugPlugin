<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Exceptions\ApiException;

interface OperationStatusFetcherInterface
{
    /**
     * @return array{status: int, body: string}
     *
     * @throws ApiException
     */
    public function getOperation(string $operationId): array;
}
