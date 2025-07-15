<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
    ) {}

    public function __invoke(NotifyPaymentRequest $notifyPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($notifyPaymentRequest);
        $payment = $paymentRequest->getPayment();
        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED) {
            // If the payment is already completed, we do not need to notify again
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE
            );

            return;
        }
        try {

            // Payload contains what's send by payplug, no need to retrieve it from PayPlug
            $payplugPayment = Payment::fromAttributes(json_decode($paymentRequest->getPayload()['http_request']['content'] ?? '{}', true));
            $details = new \ArrayObject($payment->getDetails());
            $this->paymentNotificationHandler->treat($payment, $payplugPayment, $details);

            $payment->setDetails($details->getArrayCopy());
            $this->updatePaymentState($payment);
            throw new \LogicException(sprintf('Unknown payment status "%s".', $payment->getDetails()['status'] ?? ''));

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE
            );

        } catch (\Throwable $e) {
            $paymentRequest->setResponseData([
                'error' => $e->getMessage(),
            ]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL
            );
        }
    }

    private function updatePaymentState(PaymentInterface $payment): void
    {
        match ($payment->getDetails()['status'] ?? '') {
            PayPlugApiClientInterface::STATUS_ABORTED, PayPlugApiClientInterface::STATUS_CANCELED => $this->stateMachine
                ->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL),
            PayPlugApiClientInterface::STATUS_AUTHORIZED => $this->stateMachine
                ->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_AUTHORIZE),
            PayPlugApiClientInterface::STATUS_CAPTURED => $this->stateMachine
                ->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE),
            PayPlugApiClientInterface::FAILED => $this->stateMachine
                ->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL),
            default => throw new \LogicException(sprintf('Unknown payment status "%s".', $payment->getDetails()['status'] ?? '')),
        };
    }
}
