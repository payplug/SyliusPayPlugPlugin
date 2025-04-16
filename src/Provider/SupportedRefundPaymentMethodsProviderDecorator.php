<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDecorator('sylius_refund.provider.refund_payment_methods')]
final class SupportedRefundPaymentMethodsProviderDecorator extends AbstractSupportedRefundPaymentMethodsProvider implements RefundPaymentMethodsProviderInterface
{
    protected string $gatewayFactoryName = PayPlugGatewayFactory::FACTORY_NAME;

    public function __construct(
        #[AutowireDecorated]
        RefundPaymentMethodsProviderInterface $decorated,
        RequestStack $requestStack,
        OrderRepositoryInterface $orderRepository,
        #[Autowire('%sylius_refund.supported_gateways%')]
        protected array $supportedRefundGateways
    ) {
        parent::__construct($decorated, $requestStack, $orderRepository);
    }

    protected function find(array $paymentMethods, OrderInterface $order): array
    {
        if ($this->isPayplugPayment($order)) {
            return array_filter($paymentMethods, function (PaymentMethodInterface $paymentMethod) use ($order): bool {
                $lastPayment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
                if (!$lastPayment instanceof PaymentInterface) {
                    return false;
                }
                $lastPaymentMethod = $lastPayment->getMethod();
                if (!$lastPaymentMethod instanceof PaymentMethodInterface) {
                    return false;
                }

                return $paymentMethod->getId() === $lastPaymentMethod->getId();
            });
        }

        if (null !== $order->getLastPayment() &&
            null !== $order->getLastPayment()->getMethod() &&
            $this->gatewayFactoryName === $order->getLastPayment()->getMethod()->getCode() &&
            !\in_array($this->gatewayFactoryName, $this->supportedRefundGateways, true)) {
            $this->requestStack->getSession()->getFlashBag()->add('info', 'payplug_sylius_payplug_plugin.ui.payplug_refund_gateway_is_not_activated');
        }

        foreach ($paymentMethods as $key => $paymentMethod) {
            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if ($this->gatewayFactoryName !== $gatewayConfig->getFactoryName()) {
                continue;
            }
            unset($paymentMethods[$key]);
        }

        return $paymentMethods;
    }
}
