<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;

final class BancontactSupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = BancontactGatewayFactory::FACTORY_NAME;
}
