<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Payplug\Exception\HttpException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Autoconfigure(public: true)]
class AbortPaymentProcessor
{
    public function __construct(
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.payplug')]
        private PayPlugApiClientInterface $payPlugApiClient,
    ) {
    }

    public function process(PaymentInterface $payment): void
    {
        try {
            // When a payment is failed on Sylius, also abort it on PayPlug.
            // This should prevent the case that if we are already on PayPlug payment page
            // and go to the order history in another tab to click on pay again, then fail the transaction
            // and go back on the first PayPlug payment page and succeed it, it stays failed as its first payment model is already failed
            $this->payPlugApiClient->abortPayment($payment->getDetails()['payment_id']);
        } catch (HttpException) {
        }
    }
}
