<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

interface RefundCreatorInterface
{
    /**
     * $method identifies which Hosted Fields account/submerchant to refund against — the same
     * payment method the refunded payment was originally captured with, not merely "any"
     * Hosted-Fields-configured payment method, since a merchant may configure more than one.
     *
     * @return array{status: int, body: string}
     *
     * @throws RefundAmountException
     * @throws PaymentNotFoundException
     * @throws ApiException
     */
    public function createRefund(
        PaymentMethodInterface $method,
        string $operationId,
        string $orderId,
        ?int $amount = null,
    ): array;
}
