<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Exceptions\ApiException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface OperationStatusFetcherInterface
{
    /**
     * $method is what says which PayPlug account the operation belongs to. An operation id alone
     * is not enough: since PRE-3628 two CB payment methods on different channels may be configured
     * on different accounts, and fetching with the wrong one's credentials either 404s or reads
     * another merchant's operation. Both call sites resolve the payment's own method already.
     *
     * @return array{status: int, body: string}
     *
     * @throws ApiException
     */
    public function getOperation(string $operationId, PaymentMethodInterface $method): array;
}
