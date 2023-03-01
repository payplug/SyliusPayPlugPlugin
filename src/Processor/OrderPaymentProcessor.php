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

    private FactoryInterface $stateMachineFactory;
    private string $targetState = PaymentInterface::STATE_CART;

    public function __construct(
        OrderProcessorInterface $baseOrderPaymentProcessor,
        FactoryInterface $stateMachineFactory,
        string $targetState = PaymentInterface::STATE_CART
    ) {
        $this->baseOrderPaymentProcessor = $baseOrderPaymentProcessor;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->targetState = $targetState;
    }

    public function process(OrderInterface $order): void
    {
        $this->baseOrderPaymentProcessor->process($order);

        return;
        Assert::isInstanceOf($order, \Sylius\Component\Core\Model\OrderInterface::class);

        /** @var PaymentInterface|null $lastPayment */
        $lastPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (!$lastPayment instanceof PaymentInterface) {
            $this->baseOrderPaymentProcessor->process($order);

            return;
        }

        $lastPaymentFactoryName = $this->getFactoryName($lastPayment);

        // If Apple Pay payment and is already processed by the gateway
        if (
            ApplePayGatewayFactory::FACTORY_NAME === $lastPaymentFactoryName &&
            !in_array($lastPayment->getDetails()['status'], [
                PaymentInterface::STATE_NEW,
            ], true)
        ) {
            return;
        }

        // If Apple Pay payment and is just instanced to the gateway
        if (
            ApplePayGatewayFactory::FACTORY_NAME === $lastPaymentFactoryName &&
            PaymentInterface::STATE_NEW === $lastPayment->getDetails()['status']
        ) {
            $lastPayment->setAmount(-8800);
            $stateMachine = $this->stateMachineFactory->get($lastPayment, PaymentTransitions::GRAPH);
            $stateMachine->apply(PaymentTransitions::TRANSITION_CANCEL);
        }

//        if (ApplePayGatewayFactory::FACTORY_NAME !== $lastPaymentFactoryName) {
//            $stateMachine = $this->stateMachineFactory->get($lastPayment, PaymentTransitions::GRAPH);
//            $stateMachine->apply(PaymentTransitions::TRANSITION_CANCEL);
//            $lastPayment->setAmount(-9000);
//        }

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
