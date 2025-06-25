<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webmozart\Assert\Assert;

abstract class AbstractSupportedRefundPaymentMethodsProvider
{
    protected string $gatewayFactoryName = '';

    public function __construct(
        protected RefundPaymentMethodsProviderInterface $decorated,
        protected RequestStack $requestStack,
        protected OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function findForOrder(OrderInterface $order): array
    {
        $paymentMethods = $this->decorated->findForOrder($order);
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || 'sylius_refund_order_refunds_list' !== $request->get('_route')) {
            return $paymentMethods;
        }

        return $this->find($paymentMethods, $order);
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

    protected function isPayplugPayment(OrderInterface $order): bool
    {
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        if (!$lastPayment instanceof PaymentInterface) {
            return false;
        }

        $paymentMethod = $lastPayment->getMethod();
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return false;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return false;
        }

        return $this->gatewayFactoryName === $gatewayConfig->getFactoryName();
    }
}
