<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;

final class AmexSupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = AmericanExpressGatewayFactory::FACTORY_NAME;
}
