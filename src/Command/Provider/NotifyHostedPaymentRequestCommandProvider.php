<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\NotifyHostedPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_uhf',
    ['action' => PaymentRequestInterface::ACTION_NOTIFY],
)]
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
