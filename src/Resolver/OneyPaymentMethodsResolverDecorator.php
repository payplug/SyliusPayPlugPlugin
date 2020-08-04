<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Webmozart\Assert\Assert;

class OneyPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    /** @var CurrencyContextInterface */
    private $currencyContext;

    /** @var PaymentMethodsResolverInterface */
    private $decorated;

    public function __construct(
        CurrencyContextInterface $currencyContext,
        PaymentMethodsResolverInterface $decorated
    ) {
        $this->currencyContext = $currencyContext;
        $this->decorated = $decorated;
    }

    public function getSupportedMethods(BasePaymentInterface $payment): array
    {
        Assert::isInstanceOf($payment, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($payment);

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $activeCurrencyCode = $this->currencyContext->getCurrencyCode();

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (OneyGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            /*
               TODO: ApiClient check if oney is available
               OneyChecker->isEnabled()
            */

            if (!\array_key_exists($activeCurrencyCode, OneyGatewayFactory::AUTHORIZED_CURRENCIES)) {
                unset($supportedMethods[$key]);

                continue;
            }

            if ($payment->getAmount() < OneyGatewayFactory::AUTHORIZED_CURRENCIES[$activeCurrencyCode]['min_amount']
                || $payment->getAmount() > OneyGatewayFactory::AUTHORIZED_CURRENCIES[$activeCurrencyCode]['max_amount']
            ) {
                unset($supportedMethods[$key]);

                continue;
            }

            if ($order->getItemUnits()->count() > OneyGatewayFactory::MAX_ITEMS) {
                unset($supportedMethods[$key]);

                continue;
            }
        }

        return $supportedMethods;
    }

    public function supports(BasePaymentInterface $payment): bool
    {
        return $this->decorated->supports($payment);
    }
}
