<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_apple_pay',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
final class NotifyPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_NOTIFY;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new NotifyPaymentRequest($paymentRequest->getId());
    }
}
