<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Not tagged for direct DI collection: dispatched to only by
 * NotifyPaymentRequestCommandProvider, when the payment method is `payplug` with Hosted Fields
 * selected (see PayPlugGatewayFactory::isHostedFieldsConfig()).
 */
final class NotifyHostedPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_NOTIFY;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new NotifyHostedPaymentRequest($paymentRequest->getId());
    }
}
