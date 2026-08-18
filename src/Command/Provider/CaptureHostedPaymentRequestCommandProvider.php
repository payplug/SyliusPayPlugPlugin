<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CaptureHostedPaymentRequest;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Not tagged for direct DI collection: dispatched to only by
 * CapturePaymentRequestCommandProvider, when the payment method is `payplug` with Hosted Fields
 * selected (see PayPlugGatewayFactory::isHostedFieldsConfig()).
 */
final class CaptureHostedPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $details = $paymentRequest->getPayment()->getDetails();
        if (\is_string($details['hosted_fields_created_at'] ?? null)) {
            // A payment already created via createHostedPayment() (e.g. the shopper returned to
            // /pay after a 3DS redirect) — do not create it again.
            return new OfflineCapturePaymentRequest($paymentRequest->getId());
        }

        return new CaptureHostedPaymentRequest($paymentRequest->getId());
    }
}
