<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Shared by Capture/Notify/StatusPaymentRequestCommandProvider, each tagged (and shared) across
 * every PayPlug gateway variant (Oney, Bancontact, ...): only a `payplug`-factory payment method
 * with Hosted Fields selected delegates to the Hosted-Fields-specific provider passed in — every
 * other gateway gets null here and falls through to that class's own legacy flow, unchanged.
 */
trait DelegatesToHostedFieldsCommandProviderTrait
{
    private function delegateToHostedFieldsCommandProvider(
        PaymentRequestInterface $paymentRequest,
        PaymentRequestCommandProviderInterface $hostedFieldsCommandProvider,
    ): ?object {
        if (!PayPlugGatewayFactory::isHostedFieldsConfig($paymentRequest->getPayment()->getMethod()?->getGatewayConfig())) {
            return null;
        }

        return $hostedFieldsCommandProvider->provide($paymentRequest);
    }
}
