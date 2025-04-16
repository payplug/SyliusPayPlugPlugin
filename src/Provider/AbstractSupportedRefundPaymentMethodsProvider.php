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

    public function findForChannel(ChannelInterface $channel): array
    {
        $paymentMethods = $this->decorated->findForChannel($channel);
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || 'sylius_refund_order_refunds_list' !== $request->get('_route')) {
            return $paymentMethods;
        }

        $orderNumber = $request->get('orderNumber');
        if (!is_string($orderNumber)) {
            return $paymentMethods;
        }

        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

        if (!$order instanceof OrderInterface) {
            return $paymentMethods;
        }

        return $this->find($paymentMethods, $order);
    }

    /**
     * See Sylius\RefundPlugin\Provider\SupportedRefundPaymentMethodsProvider
     * The "findForChannel" method is deprecated and will be removed in 2.0. Use "findForOrder" instead
     */
    public function findForOrder(OrderInterface $order): array
    {
        $channel = $order->getChannel();
        Assert::notNull($channel);
        $paymentMethods = $this->decorated->findForChannel($channel);

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
