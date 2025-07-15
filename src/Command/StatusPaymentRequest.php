<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

class StatusPaymentRequest extends AbstractPayplugPaymentRequest
{
    use PaymentRequestHashAwareTrait;

    public function __construct(protected ?string $hash, private string $forcedStatus = '') {
        parent::__construct($hash);
    }

    public function getForcedStatus() : string
    {
        return $this->forcedStatus;
    }
}
