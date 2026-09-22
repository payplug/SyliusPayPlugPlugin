<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\StatusPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_oney',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_bancontact',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_american_express',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_apple_pay',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_scalapay',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_wero',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
final class StatusPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    use DelegatesToHostedFieldsCommandProviderTrait;

    public function __construct(
        private RequestStack $requestStack,
        #[Autowire(service: StatusHostedPaymentRequestCommandProvider::class)]
        private PaymentRequestCommandProviderInterface $hostedFieldsCommandProvider,
    ) {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_STATUS;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $hostedFieldsResult = $this->delegateToHostedFieldsCommandProvider($paymentRequest, $this->hostedFieldsCommandProvider);
        if (null !== $hostedFieldsResult) {
            return $hostedFieldsResult;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return new StatusPaymentRequest($paymentRequest->getId());
        }
        $forcedStatus = $request->query->getString('status');

        return new StatusPaymentRequest($paymentRequest->getId(), $forcedStatus);
    }
}
