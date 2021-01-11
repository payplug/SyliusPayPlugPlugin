<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Webmozart\Assert\Assert;

final class PayPlugPaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    /** @var CurrencyContextInterface */
    private $currencyContext;

    /** @var PaymentMethodRepositoryInterface */
    private $paymentMethodRepository;

    public function __construct(
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        CurrencyContextInterface $currencyContext
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->currencyContext = $currencyContext;
    }

    public function getSupportedMethods(BasePaymentInterface $payment): array
    {
        Assert::isInstanceOf($payment, PaymentInterface::class);
        Assert::true($this->supports($payment), 'This payment method is not support by resolver');

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();

        /** @var PaymentMethod[] $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->findEnabledForChannel($channel);

        $supportedMethods = [];

        $activeCurrencyCode = $this->currencyContext->getCurrencyCode();

        foreach ($paymentMethods as $paymentMethod) {
            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (PayPlugGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
                $supportedMethods[] = $paymentMethod;
            } elseif (\in_array($activeCurrencyCode, array_keys(PayPlugGatewayFactory::AUTHORIZED_CURRENCIES), true)
                && $payment->getAmount() >= PayPlugGatewayFactory::AUTHORIZED_CURRENCIES[$activeCurrencyCode]['min_amount']
                && $payment->getAmount() <= PayPlugGatewayFactory::AUTHORIZED_CURRENCIES[$activeCurrencyCode]['max_amount']
            ) {
                $supportedMethods[] = $paymentMethod;
            }
        }

        return $supportedMethods;
    }

    public function supports(BasePaymentInterface $payment): bool
    {
        return $payment instanceof PaymentInterface &&
            null !== $payment->getOrder() &&
            null !== $payment->getOrder()->getChannel()
        ;
    }
}
