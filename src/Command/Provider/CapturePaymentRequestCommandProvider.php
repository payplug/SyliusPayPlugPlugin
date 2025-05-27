<?php

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
final class CapturePaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new CapturePaymentRequest($paymentRequest->getId());
    }
}
