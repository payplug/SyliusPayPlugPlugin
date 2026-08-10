<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Temporary stand-in for the real UPC-backed Hosted Fields payment processor (PRE-3551).
 * Deliberately does not call any external API: PayPlugApiClient is built on the modern
 * payplug-php REST/OAuth2 SDK, which has no bridge to the legacy Dalenys Hosted Fields
 * token format. Stores the token/brand/save-card intent on the payment so the real
 * adapter can pick it up once it lands.
 */
final class NullHostedFieldsPaymentProcessor implements HostedFieldsPaymentProcessorInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function process(PaymentInterface $payment, string $hfToken, string $selectedBrand, bool $saveCard): void
    {
        $this->logger->info('Hosted Fields token received, awaiting UPC payment processing (PRE-3551).', [
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
                'status' => PaymentInterface::STATE_PROCESSING,
            ],
        ));
    }
}
