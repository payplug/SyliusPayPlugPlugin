<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

class StatusHostedPaymentRequest extends AbstractPayplugPaymentRequest
{
    public function __construct(protected ?string $hash, private string $forcedStatus = '')
    {
        parent::__construct($hash);
    }

    public function getForcedStatus(): string
    {
        return $this->forcedStatus;
    }
}
