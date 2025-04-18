<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Processor;

use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use SM\Factory\FactoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Webmozart\Assert\Assert;

final class OrderPaymentProcessor implements OrderProcessorInterface
{
    private OrderProcessorInterface $baseOrderPaymentProcessor;


    public function __construct(
        OrderProcessorInterface $baseOrderPaymentProcessor,
    ) {
        $this->baseOrderPaymentProcessor = $baseOrderPaymentProcessor;
    }

    public function process(OrderInterface $order): void
    {
        Assert::isInstanceOf($order, \Sylius\Component\Core\Model\OrderInterface::class);

        /** @var PaymentInterface|null $payment */
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (
            null !== $payment &&
            PaymentInterface::STATE_COMPLETED === $payment->getDetails()['status'] &&
            ApplePayGatewayFactory::FACTORY_NAME === $this->getFactoryName($payment)
        ) {
            return;
        }

        if (
            null !== $payment &&
            ApplePayGatewayFactory::FACTORY_NAME !== $this->getFactoryName($payment)
        ) {
            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            $stateMachine->apply(PaymentTransitions::TRANSITION_CANCEL);
        }

        $this->baseOrderPaymentProcessor->process($order);
    }

    private function getFactoryName(PaymentInterface $payment): string
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        return $gatewayConfig->getFactoryName();
    }
}
