<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Webmozart\Assert\Assert;

#[AsDecorator('sylius.resolver.payment_methods')]
final class OneyPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PaymentMethodsResolverInterface $decorated,
        private CurrencyContextInterface $currencyContext,
        private OneyCheckerInterface $oneyChecker,
    ) {
    }

    public function getSupportedMethods(BasePaymentInterface $subject): array
    {
        Assert::isInstanceOf($subject, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($subject);

        /** @var OrderInterface $order */
        $order = $subject->getOrder();

        // The payment's own currency, not the one currently displayed: isPriceEligible() below
        // compares $subject->getAmount() against Oney's per-currency bounds, and on a
        // multi-currency channel the display currency need not be the order's. Falls back to the
        // context for a payment carrying no currency, which the Sylius contract allows.
        $activeCurrencyCode = $subject->getCurrencyCode() ?? $this->currencyContext->getCurrencyCode();

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (OneyGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            $countryCodeShipping = null;
            $countryCodeBilling = null;

            if (null !== $order->getShippingAddress() && null !== $order->getBillingAddress()) {
                $countryCodeShipping = $order->getShippingAddress()->getCountryCode();
                $countryCodeBilling = $order->getBillingAddress()->getCountryCode();
            }

            if (
                !$this->oneyChecker->isEnabled() ||
                !$this->oneyChecker->isPriceEligible($subject->getAmount() ?? 0, $activeCurrencyCode) ||
                !$this->oneyChecker->isNumberOfProductEligible($order->getItemUnits()->count()) ||
                !$this->oneyChecker->isCountryEligible($countryCodeShipping, $countryCodeBilling)
            ) {
                unset($supportedMethods[$key]);
            }
        }

        return $supportedMethods;
    }

    public function supports(BasePaymentInterface $subject): bool
    {
        return $this->decorated->supports($subject);
    }
}
