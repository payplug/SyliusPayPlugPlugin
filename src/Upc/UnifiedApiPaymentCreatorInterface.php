<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Output\PaymentOutput;

interface UnifiedApiPaymentCreatorInterface
{
    /**
     * @throws InvalidHostedFieldException if $dto is a HostedFieldDto that fails validation
     * @throws InvalidPaymentException if $dto is a PaymentDto that fails validation
     * @throws ApiException
     */
    public function createPayment(PaymentRequestPayload $dto): PaymentOutput;
}
