<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

#[Autoconfigure(public: true)]
class AbortPaymentProcessor
{
    public function __construct(
        private PayPlugApiClientFactory $payplugApiClientFactory,
    ) {
    }

    #[AsCompletedListener(workflow: PaymentTransitions::GRAPH, transition: PaymentTransitions::TRANSITION_FAIL)]
    public function onFailedCompleteTransitionEvent(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof PaymentInterface) {
            return;
        }

        $this->process($subject);
    }

    public function process(PaymentInterface $payment): void
    {
        $paymentId = $payment->getDetails()['payment_id'] ?? null;
        if (null === $paymentId) {
            // Payment not even started on payplug
            return;
        }

        $method = $payment->getMethod();
        if (null === $method) {
            return;
        }
        $client = $this->payplugApiClientFactory->createForPaymentMethod($method);
        try {
            // When a payment is failed on Sylius, also abort it on PayPlug.
            // This should prevent the case that if we are already on PayPlug payment page
            // and go to the order history in another tab to click on pay again, then fail the transaction
            // and go back on the first PayPlug payment page and succeed it, it stays failed as its first payment model is already failed
            $client->abortPayment($paymentId);
        } catch (HttpException) {
        }
    }
}
