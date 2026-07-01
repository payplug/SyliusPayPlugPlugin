<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private PaymentTransitionApplier $paymentTransitionApplier,
    ) {}

    public function __invoke(NotifyPaymentRequest $notifyPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($notifyPaymentRequest);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();

        try {
            $payload = $paymentRequest->getPayload();
            $content = $payload['http_request']['content'] ?? null; // @phpstan-ignore-line
            if (!is_string($content) || '' === $content) {
                throw new \LogicException('Invalid PayPlug notification payload.');
            }

            $method = $payment->getMethod();
            if (null === $method) {
                throw new \LogicException('Payment method is not set for the payment.');
            }

            $client = $this->apiClientFactory->createForPaymentMethod($method);
            $resource = $client->treat($content);

            if ($resource instanceof Payment && $payment->getState() === PaymentInterface::STATE_COMPLETED) {
                // If the payment is already completed, we do not need to update it again
                $this->stateMachine->apply(
                    $paymentRequest,
                    PaymentRequestTransitions::GRAPH,
                    PaymentRequestTransitions::TRANSITION_COMPLETE,
                );

                return;
            }

            $details = new \ArrayObject($payment->getDetails());
            $this->paymentNotificationHandler->treat($payment, $resource, $details);
            $this->refundNotificationHandler->treat($payment, $resource, $details);

            $payment->setDetails($details->getArrayCopy());
            if ($resource instanceof Payment) {
                $this->paymentTransitionApplier->apply($payment);
            }

            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );
        } catch (\Throwable $e) {
            $paymentRequest->setResponseData([
                'error' => $e->getMessage(),
            ]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );
        }
    }
}
