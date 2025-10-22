<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\StatusPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StatusPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
    ) {}

    public function __invoke(StatusPaymentRequest $statusPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($statusPaymentRequest);
        /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        if ('' !== $statusPaymentRequest->getForcedStatus()) {
            $this->handleForcedStatus($statusPaymentRequest, $paymentRequest);

            return;
        }
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        // We don't have a forced status, so we retrieve the payment status from PayPlug
        $client = $this->apiClientFactory->createForPaymentMethod($method);
        // @phpstan-ignore-next-line - getDetails() return mixed
        $payplugPayment = $client->retrieve($payment->getDetails()['payment_id'] ?? throw new \LogicException('No PayPlug payment ID found in payment details.'));

        $paymentRequest->setResponseData((array) $payplugPayment);
        $details = new \ArrayObject($payment->getDetails());
        $this->paymentNotificationHandler->treat($payment, $payplugPayment, $details);

        $payment->setDetails($details->getArrayCopy());
        $this->updatePaymentState($payment);

        // Mark the PaymentRequest as completed
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    private function handleForcedStatus(
        StatusPaymentRequest $statusPaymentRequest,
        PaymentRequestInterface $paymentRequest,
    ): void {
        $payment = $paymentRequest->getPayment();

        $payment->setDetails([
            ...$payment->getDetails(),
            'status' => $statusPaymentRequest->getForcedStatus(),
        ]);

        $this->updatePaymentState($payment);

        // Mark the PaymentRequest as completed
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
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
            default => throw new \LogicException(sprintf('Unknown payment status "%s".', $payment->getDetails()['status'] ?? '')), // @phpstan-ignore-line - getDetails() return mixed
        };
    }
}
