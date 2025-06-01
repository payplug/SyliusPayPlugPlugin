<?php

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\StatusPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_STATUS]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_STATUS]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_STATUS]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_STATUS]
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_apple_pay',
    ['action' => PaymentRequestInterface::ACTION_STATUS]
)]
final class StatusPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_STATUS;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return new StatusPaymentRequest($paymentRequest->getId());
        }
        $forcedStatus = $request->query->getString('status');

        return new StatusPaymentRequest($paymentRequest->getId(), $forcedStatus);
    }
}
