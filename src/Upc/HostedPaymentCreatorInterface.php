<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Output\HostedPaymentOutput;

interface HostedPaymentCreatorInterface
{
    /**
     * @throws InvalidHostedFieldException
     * @throws ApiException
     */
    public function createHostedPayment(HostedFieldDto $dto): HostedPaymentOutput;
}
