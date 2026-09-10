<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

/**
 * Identifies which capture path a PaymentCaptureOutcomeApplier::failPaymentRequest() call
 * came from, so its log message stays distinguishable between the two handlers without each
 * caller passing a free-text string.
 */
enum PaymentCaptureFlow: string
{
    case Alias = 'Alias';
    case Hosted = 'Hosted';
}
