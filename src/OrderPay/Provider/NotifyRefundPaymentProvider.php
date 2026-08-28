<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\OrderPay\Provider;

use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use Sylius\Bundle\PaymentBundle\Attribute\AsNotifyPaymentProvider;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;

#[AsNotifyPaymentProvider]
final class NotifyRefundPaymentProvider implements NotifyPaymentProviderInterface
{
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
    ) {
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        /** @var ?string $paymentId */
        $paymentId = $request->getPayload()->get('payment_id');
        if (null === $paymentId) {
            throw new \InvalidArgumentException('Order number not found in request payload');
        }

        $payment = $this->paymentRepository->findOneByPayPlugPaymentId($paymentId);
        if (null === $payment || $payment->getMethod() !== $paymentMethod) {
            throw new \InvalidArgumentException(sprintf('Payment with ID "%s" not found', $paymentId));
        }

        return $payment;
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return \str_contains($paymentMethod->getGatewayConfig()?->getFactoryName() ?? '', 'payplug') &&
            $request->getPayload()->has('id') &&
            $request->getPayload()->has('metadata') &&
            $request->getPayload()->has('object') &&
            $request->getPayload()->get('object') === 'refund';
    }
}
