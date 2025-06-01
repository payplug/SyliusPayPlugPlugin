<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

class StatusPaymentRequest implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(protected ?string $hash, private string $forcedStatus = '') {}

    public function getForcedStatus() : string
    {
        return $this->forcedStatus;
    }
}
