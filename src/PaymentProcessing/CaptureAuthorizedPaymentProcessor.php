<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use Payum\Core\Bridge\Spl\ArrayObject;
use Sylius\Component\Core\Model\PaymentInterface;

final class CaptureAuthorizedPaymentProcessor
{
    private PayPlugApiClientFactory $apiClientFactory;
    private PaymentNotificationHandler $paymentNotificationHandler;

    public function __construct(
        PayPlugApiClientFactory $apiClientFactory,
        PaymentNotificationHandler $paymentNotificationHandler
    ) {
        $this->apiClientFactory = $apiClientFactory;
        $this->paymentNotificationHandler = $paymentNotificationHandler;
    }

    public function process(PaymentInterface $payment): void
    {
        $details = new ArrayObject($payment->getDetails());
        $method = $payment->getMethod();
        if (null === $method) {
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

        $client = $this->apiClientFactory->createForPaymentMethod($method);
        $payplugPayment = $client->retrieve($details['payment_id']);

        $updatedPayment = $payplugPayment->capture($client->getConfiguration());
        if (null === $updatedPayment) {
            throw new \LogicException('Payment capture failed');
        }

        $this->paymentNotificationHandler->treat($payment, $updatedPayment, $details);
        $payment->setDetails($details->getArrayCopy());
    }
}
