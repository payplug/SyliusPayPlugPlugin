<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\SupportedMethodsProvider;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Webmozart\Assert\Assert;

final class PayPlugPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    /** @var PaymentMethodsResolverInterface */
    private $decorated;

    /** @var SupportedMethodsProvider  */
    private $supportedMethodsProvider;

    public function __construct(
        PaymentMethodsResolverInterface $decorated,
        SupportedMethodsProvider $supportedMethodsProvider
    ) {
        $this->decorated = $decorated;
        $this->supportedMethodsProvider = $supportedMethodsProvider;
    }

    public function getSupportedMethods(BasePaymentInterface $payment): array
    {
        Assert::isInstanceOf($payment, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($payment);

        return $this->supportedMethodsProvider->provide(
            $supportedMethods,
            PayPlugGatewayFactory::FACTORY_NAME,
            PayPlugGatewayFactory::AUTHORIZED_CURRENCIES,
            $payment->getAmount() ?? 0);
    }

    public function supports(BasePaymentInterface $payment): bool
    {
        return $this->decorated->supports($payment);
    }
}
