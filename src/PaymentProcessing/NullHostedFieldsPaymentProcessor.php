<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Captures the Hosted Fields form submission (token, brand, save-card intent, and enough card
 * metadata to persist a Card entity later) onto the payment's details. The actual UPC payment
 * creation call happens afterwards, asynchronously from this class's point of view, in
 * CaptureHostedPaymentRequestHandler — this class only ever touches the Payment, never an
 * external API.
 */
final class NullHostedFieldsPaymentProcessor implements HostedFieldsPaymentProcessorInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function process(PaymentInterface $payment, HostedFieldsCaptureData $captureData): void
    {
        $this->logger->info('Hosted Fields token received, awaiting UPC payment processing.', [
            'payment_id' => $payment->getId(),
            'selected_brand' => $captureData->selectedBrand,
            'save_card' => $captureData->saveCard,
        ]);

        $payment->setDetails(\array_merge(
            $payment->getDetails(),
            [
                'hosted_fields_token' => $captureData->hfToken,
                'hosted_fields_selected_brand' => $captureData->selectedBrand,
                'hosted_fields_save_card' => $captureData->saveCard,
                'hosted_fields_last4' => $captureData->last4,
                'hosted_fields_expiration_month' => $captureData->expirationMonth,
                'hosted_fields_expiration_year' => $captureData->expirationYear,
                'hosted_fields_country' => $captureData->countryCode,
                'status' => PaymentInterface::STATE_PROCESSING,
            ],
        ));
    }
}
