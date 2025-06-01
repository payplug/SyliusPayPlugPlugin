<?php

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\CapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\Command\Offline\CapturePaymentRequest as OfflineCapturePaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_CAPTURE]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_apple_pay',
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
        $payment = $paymentRequest->getPayment();
        $details = $payment->getDetails();

        if ($this->isAlreadyCreated($paymentRequest)) {
            // The payment has already been created, let's use the offline capture request to be redirected to the thank-you page
            // TODO: use our own AlreadyPaidCapturePaymentRequest class
            return new OfflineCapturePaymentRequest($paymentRequest->getId());
        }

        return new CapturePaymentRequest($paymentRequest->getId());
    }

    private function isAlreadyCreated(PaymentRequestInterface $paymentRequest): bool
    {
        $payment = $paymentRequest->getPayment();
        $details = $payment->getDetails();

        if (
            isset($details['status'], $details['payment_id']) &&
            PayPlugApiClientInterface::STATUS_CREATED !== $details['status']
        ) {
            return true;
        }

        if (
            array_key_exists('status', $details) &&
            PayPlugApiClientInterface::STATUS_CAPTURED === $details['status']
        ) {
            return true;
        }

        return false;
    }
}
