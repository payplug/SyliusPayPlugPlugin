<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\WeroGatewayFactory;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(SupportedRefundPaymentMethodsProviderDecorator::class)]
final class WeroSupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = WeroGatewayFactory::FACTORY_NAME;
}
