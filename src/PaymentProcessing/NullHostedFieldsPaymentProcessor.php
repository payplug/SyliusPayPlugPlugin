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

    public function process(
        PaymentInterface $payment,
        string $hfToken,
        string $selectedBrand,
        bool $saveCard,
        string $last4,
        int $expirationMonth,
        int $expirationYear,
        string $countryCode,
    ): void {
        $this->logger->info('Hosted Fields token received, awaiting UPC payment processing.', [
            'payment_id' => $payment->getId(),
            'selected_brand' => $selectedBrand,
            'save_card' => $saveCard,
        ]);

        $payment->setDetails(\array_merge(
            $payment->getDetails(),
            [
                'hosted_fields_token' => $hfToken,
                'hosted_fields_selected_brand' => $selectedBrand,
                'hosted_fields_save_card' => $saveCard,
                'hosted_fields_last4' => $last4,
                'hosted_fields_expiration_month' => $expirationMonth,
                'hosted_fields_expiration_year' => $expirationYear,
                'hosted_fields_country' => $countryCode,
                'status' => PaymentInterface::STATE_PROCESSING,
            ],
        ));
    }
}
