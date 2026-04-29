<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(SupportedRefundPaymentMethodsProviderDecorator::class)]
final class ScalapaySupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = ScalapayGatewayFactory::FACTORY_NAME;
}
