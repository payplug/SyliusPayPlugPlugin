<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\OrderPay\Provider;

use Sylius\Bundle\PaymentBundle\Attribute\AsNotifyPaymentProvider;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * This provider is used to retrieve the payment from the order based on the request payload that Payplug sends.
 *
 * @see \Sylius\Bundle\PaymentBundle\Action\PaymentMethodNotifyAction
 */
#[AsNotifyPaymentProvider]
final class NotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        /** @var string|null $orderNumber */
        $orderNumber = $request->getPayload()->all('metadata')['order_number'] ?? null;
        if (null === $orderNumber) {
            throw new \InvalidArgumentException('Order number not found in request payload');
        }
        $order = $this->getOrderFromReference($orderNumber);

        $payId = $request->getPayload()->getString('id');
        $payment = $order->getPayments()->filter(function (PaymentInterface $payment) use ($payId) {
            return $payment->getDetails()['payment_id'] === $payId;
        })->first();
        if (false === $payment) {
            throw new \InvalidArgumentException(sprintf('Payment with ID "%s" not found in order "%s"', $payId, $orderNumber));
        }

        return $payment;
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return \str_contains($paymentMethod->getGatewayConfig()?->getFactoryName() ?? '', 'payplug') &&
            $request->getPayload()->has('id') &&
            $request->getPayload()->has('metadata');
    }

    private function getOrderFromReference(string $orderReference): OrderInterface
    {
        $order = $this->orderRepository->findOneBy(['number' => $orderReference]);
        if (null === $order) {
            // @see \PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator order_number can be number/token or id
            $order = $this->orderRepository->findOneBy(['tokenValue' => $orderReference]);
        }
        if (null === $order) {
            $order = $this->orderRepository->findOneBy(['id' => $orderReference]);
        }
        if (!$order instanceof OrderInterface) {
            throw new \InvalidArgumentException(sprintf('Order with reference "%s" not found', $orderReference));
        }

        return $order;
    }
}
