<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

final class SupportedRefundPaymentMethodsProviderDecorator implements RefundPaymentMethodsProviderInterface
{
    /** @var RefundPaymentMethodsProviderInterface */
    private $decorated;

    /** @var RequestStack */
    private $requestStack;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var FlashBagInterface */
    private $flashBag;

    /** @var array */
    private $supportedRefundGateways;

    public function __construct(
        RefundPaymentMethodsProviderInterface $decorated,
        RequestStack $requestStack,
        OrderRepositoryInterface $orderRepository,
        FlashBagInterface $flashBag,
        array $supportedRefundGateways
    ) {
        $this->decorated = $decorated;
        $this->requestStack = $requestStack;
        $this->orderRepository = $orderRepository;
        $this->flashBag = $flashBag;
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

        if (!$order instanceof OrderInterface || $this->isPayPlugPayment($order)) {
            return $paymentMethods;
        }

        if (null !== $order->getLastPayment() &&
            null !== $order->getLastPayment()->getMethod() &&
            $order->getLastPayment()->getMethod()->getCode() === PayPlugGatewayFactory::FACTORY_NAME &&
            !\in_array(PayPlugGatewayFactory::FACTORY_NAME, $this->supportedRefundGateways, true)) {
            $this->flashBag->add('info', 'payplug_sylius_payplug_plugin.ui.payplug_refund_gateway_is_not_activated');
        }

        foreach ($paymentMethods as $key => $paymentMethod) {
            if (PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getCode()) {
                continue;
            }
            unset($paymentMethods[$key]);
        }

        return $paymentMethods;
    }

    private function isPayPlugPayment(OrderInterface $order): bool
    {
        $firstPayment = $order->getPayments()->first();
        if (!$firstPayment instanceof PaymentInterface) {
            return false;
        }

        $firstPaymentMethod = $firstPayment->getMethod();
        if (!$firstPaymentMethod instanceof PaymentMethodInterface) {
            return false;
        }

        if (PayPlugGatewayFactory::FACTORY_NAME !== $firstPaymentMethod->getCode()) {
            return false;
        }

        return true;
    }
}
