<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;

final class ApplePaySupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = ApplePayGatewayFactory::FACTORY_NAME;
}
