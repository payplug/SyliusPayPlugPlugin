<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Resolver;

use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\SupportedMethodsProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Webmozart\Assert\Assert;

#[AsDecorator('sylius.resolver.payment_methods')]
final class ScalapayPaymentMethodsResolverDecorator implements PaymentMethodsResolverInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PaymentMethodsResolverInterface $decorated,
        private SupportedMethodsProvider $supportedMethodsProvider,
    ) {
    }

    public function getSupportedMethods(BasePaymentInterface $subject): array
    {
        Assert::isInstanceOf($subject, Payment::class);
        $supportedMethods = $this->decorated->getSupportedMethods($subject);

        /** @var OrderInterface $order */
        $order = $subject->getOrder();
        $billingCountryCode = $order->getBillingAddress()?->getCountryCode();

        return $this->supportedMethodsProvider->provide(
            $supportedMethods,
            ScalapayGatewayFactory::FACTORY_NAME,
            $subject->getAmount() ?? 0,
            $billingCountryCode,
        );
    }

    public function supports(BasePaymentInterface $subject): bool
    {
        return $this->decorated->supports($subject);
    }
}
