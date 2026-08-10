<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\OrderPay\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use Sylius\Bundle\PaymentBundle\Attribute\AsNotifyPaymentProvider;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the Payment a Unified API webhook notification belongs to. Distinct from
 * NotifyPaymentProvider (the legacy-shaped provider used by every other PayPlug gateway in this
 * plugin) because the Unified API's webhook body has none of the fields that one matches on
 * (metadata, object).
 *
 * @see \Sylius\Bundle\PaymentBundle\Action\PaymentMethodNotifyAction
 */
#[AsNotifyPaymentProvider]
final class UhfNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return UhfGatewayFactory::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName() &&
            $request->getPayload()->has('id') &&
            $request->getPayload()->has('execCode') &&
            $request->getPayload()->has('orderId')
        ;
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        $orderId = $request->getPayload()->getString('orderId');
        $order = $this->orderRepository->findOneBy(['id' => $orderId]);

        if (!$order instanceof OrderInterface) {
            throw new \InvalidArgumentException(sprintf('Order with id "%s" not found', $orderId));
        }

        $operationId = $request->getPayload()->getString('id');
        $payment = $order->getPayments()->filter(
            static fn (PaymentInterface $payment): bool => $operationId === ($payment->getDetails()['payment_id'] ?? null),
        )->first();

        if (false === $payment) {
            throw new \InvalidArgumentException(sprintf('Payment with operation id "%s" not found in order "%s"', $operationId, $orderId));
        }

        return $payment;
    }
}
