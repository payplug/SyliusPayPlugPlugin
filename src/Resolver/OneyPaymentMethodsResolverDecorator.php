<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Webmozart\Assert\Assert;

final class OneyPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    /** @var CurrencyContextInterface */
    private $currencyContext;

    /** @var PaymentMethodsResolverInterface */
    private $decorated;

    /** @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface */
    private $oneyChecker;

    public function __construct(
        PaymentMethodsResolverInterface $decorated,
        CurrencyContextInterface $currencyContext,
        OneyCheckerInterface $oneyChecker
    ) {
        $this->currencyContext = $currencyContext;
        $this->decorated = $decorated;
        $this->oneyChecker = $oneyChecker;
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

            $countryCodeShipping = null;
            $countryCodeBilling = null;

            if(null !== $order->getShippingAddress() && null !== $order->getBillingAddress()) {
                $countryCodeShipping = $order->getShippingAddress()->getCountryCode();
                $countryCodeBilling = $order->getBillingAddress()->getCountryCode();
            }

            if (!$this->oneyChecker->isEnabled() ||
                !$this->oneyChecker->isPriceEligible($payment->getAmount() ?? 0, $activeCurrencyCode) ||
                !$this->oneyChecker->isNumberOfProductEligible($order->getItemUnits()->count()) ||
                !$this->oneyChecker->isCountryEligible($countryCodeShipping, $countryCodeBilling)) {
                unset($supportedMethods[$key]);
            }
        }

        return $supportedMethods;
    }

    public function supports(BasePaymentInterface $payment): bool
    {
        return $this->decorated->supports($payment);
    }
}
