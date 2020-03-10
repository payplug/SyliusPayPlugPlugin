<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
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
    )
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->currencyContext = $currencyContext;
    }

    public function getSupportedMethods(BasePaymentInterface $payment): array
    {
        /** @var PaymentInterface $payment */
        Assert::isInstanceOf($payment, PaymentInterface::class);
        Assert::true($this->supports($payment), 'This payment method is not support by resolver');

        /** @var PaymentMethod[] $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->findEnabledForChannel($payment->getOrder()->getChannel());

        $supportedMethods = [];

        foreach ($paymentMethods as $paymentMethod) {
            if (PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName()) {
                $supportedMethods[] = $paymentMethod;
            } elseif (in_array($this->currencyContext->getCurrencyCode(), PayPlugGatewayFactory::AUTHORIZED_CURRENCY)) {
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
