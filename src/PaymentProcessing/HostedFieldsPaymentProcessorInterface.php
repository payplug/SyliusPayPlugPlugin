<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Sylius\Component\Core\Model\PaymentInterface;

interface HostedFieldsPaymentProcessorInterface
{
    public function process(
        PaymentInterface $payment,
        string $hfToken,
        string $selectedBrand,
        bool $saveCard,
        string $last4,
        int $expirationMonth,
        int $expirationYear,
        string $countryCode,
    ): void;
}
