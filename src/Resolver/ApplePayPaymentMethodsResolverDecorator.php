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
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Webmozart\Assert\Assert;

#[AsDecorator('sylius.resolver.payment_methods')]
final class ApplePayPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PaymentMethodsResolverInterface $decorated,
        private SupportedMethodsProvider $supportedMethodsProvider,
        private ApplePayCheckerInterface $applePayChecker
    ) {
    }

    public function getSupportedMethods(BasePaymentInterface $subject): array
    {
        Assert::isInstanceOf($subject, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($subject);

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
            $subject->getAmount() ?? 0
        );
    }

    public function supports(BasePaymentInterface $subject): bool
    {
        return $this->decorated->supports($subject);
    }
}
