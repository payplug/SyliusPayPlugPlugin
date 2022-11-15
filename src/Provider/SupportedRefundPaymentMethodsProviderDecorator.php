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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SupportedRefundPaymentMethodsProviderDecorator implements RefundPaymentMethodsProviderInterface
{
    /** @var RefundPaymentMethodsProviderInterface */
    private $decorated;

    /** @var RequestStack */
    private $requestStack;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var array */
    private $supportedRefundGateways;

    public function __construct(
        RefundPaymentMethodsProviderInterface $decorated,
        RequestStack $requestStack,
        OrderRepositoryInterface $orderRepository,
        array $supportedRefundGateways
    ) {
        $this->decorated = $decorated;
        $this->requestStack = $requestStack;
        $this->orderRepository = $orderRepository;
        $this->supportedRefundGateways = $supportedRefundGateways;
    }

    public function findForChannel(ChannelInterface $channel): array
    {
        $paymentMethods = $this->decorated->findForChannel($channel);
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || 'sylius_refund_order_refunds_list' !== $request->get('_route')) {
            return $paymentMethods;
        }

        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findOneByNumber($request->get('orderNumber'));

        if (!$order instanceof OrderInterface) {
            return $paymentMethods;
        }

        if ($this->isPayPlugPayment($order)) {
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
            PayPlugGatewayFactory::FACTORY_NAME === $order->getLastPayment()->getMethod()->getCode() &&
            !\in_array(PayPlugGatewayFactory::FACTORY_NAME, $this->supportedRefundGateways, true)) {
            $this->requestStack->getSession()->getFlashBag()->add('info', 'payplug_sylius_payplug_plugin.ui.payplug_refund_gateway_is_not_activated');
        }

        foreach ($paymentMethods as $key => $paymentMethod) {
            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if (PayPlugGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
                continue;
            }
            unset($paymentMethods[$key]);
        }

        return $paymentMethods;
    }

    private function isPayPlugPayment(OrderInterface $order): bool
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

        if (PayPlugGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            return false;
        }

        return true;
    }
}
