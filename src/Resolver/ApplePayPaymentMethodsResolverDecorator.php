<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Checker\ApplePayCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\SupportedMethodsProvider;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Webmozart\Assert\Assert;

final class ApplePayPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    private PaymentMethodsResolverInterface $decorated;

    private SupportedMethodsProvider $supportedMethodsProvider;

    private ApplePayCheckerInterface $applePayChecker;

    public function __construct(
        PaymentMethodsResolverInterface $decorated,
        SupportedMethodsProvider $supportedMethodsProvider,
        ApplePayCheckerInterface $applePayChecker
    ) {
        $this->decorated = $decorated;
        $this->supportedMethodsProvider = $supportedMethodsProvider;
        $this->applePayChecker = $applePayChecker;
    }

    public function getSupportedMethods(BasePaymentInterface $payment): array
    {
        Assert::isInstanceOf($payment, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($payment);

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (ApplePayGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            if (!$this->applePayChecker->isDeviceReady()) {
                unset($supportedMethods[$key]);
            }
        }

        return $this->supportedMethodsProvider->provide(
            $supportedMethods,
            ApplePayGatewayFactory::FACTORY_NAME,
            ApplePayGatewayFactory::AUTHORIZED_CURRENCIES,
            $payment->getAmount() ?? 0
        );
    }

    public function supports(BasePaymentInterface $payment): bool
    {
        return $this->decorated->supports($payment);
    }
}
