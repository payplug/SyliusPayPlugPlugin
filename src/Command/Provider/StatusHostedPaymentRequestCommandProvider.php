<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Provider;

use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;

#[AutoconfigureTag(
    'payplug_sylius_payplug_plugin.command_provider.payplug_uhf',
    ['action' => PaymentRequestInterface::ACTION_STATUS],
)]
final class StatusHostedPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
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
            return new StatusHostedPaymentRequest($paymentRequest->getId());
        }

        return new StatusHostedPaymentRequest($paymentRequest->getId(), $request->query->getString('status'));
    }
}
