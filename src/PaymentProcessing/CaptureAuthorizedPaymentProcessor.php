<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use Payum\Core\Bridge\Spl\ArrayObject;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class CaptureAuthorizedPaymentProcessor
{
    public function __construct(
        private PayPlugApiClientFactory $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
    ) {
    }

    #[AsTransitionListener(workflow: PaymentTransitions::GRAPH, transition: PaymentTransitions::TRANSITION_COMPLETE)]
    public function onCompletePaymentTransitionEvent(TransitionEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof PaymentInterface) {
            return;
        }

        $this->process($subject);
    }

    public function process(PaymentInterface $payment): void
    {
        $details = new ArrayObject($payment->getDetails());
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            return;
        }
        if (PayPlugGatewayFactory::FACTORY_NAME !== $method->getGatewayConfig()?->getFactoryName()) {
            // Not a supported payment method
            return;
        }

        if (!isset($details['status']) || PayPlugApiClientInterface::STATUS_AUTHORIZED !== $details['status']) {
            // Not an authorized payment, do nothing
            return;
        }
        if (!isset($details['payment_id'])) {
            // not a payplug payment id ? do nothing
            return;
        }
        $paymentId = $details['payment_id'];
        if (!is_string($paymentId)) {
            throw new \LogicException('Payment id is not a string');
        }

        $client = $this->apiClientFactory->createForPaymentMethod($method);
        $payplugPayment = $client->retrieve($paymentId);

        $updatedPayment = $payplugPayment->capture($client->getConfiguration());
        if (null === $updatedPayment) {
            throw new \LogicException('Payment capture failed');
        }

        $this->paymentNotificationHandler->treat($payment, $updatedPayment, $details);
        $payment->setDetails($details->getArrayCopy());
    }
}
