<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\StatusPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StatusPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private PaymentTransitionApplier $paymentTransitionApplier,
        private LoggerInterface $logger,
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

        if (UhfGatewayFactory::FACTORY_NAME === $method->getGatewayConfig()?->getFactoryName()) {
            // The Unified API's webhook (see NotifyPaymentRequestHandler) is the single source of
            // truth for the final outcome, whether or not a 3DS challenge happened — nothing to
            // synchronously resolve here. Complete the PaymentRequest so the shopper reaches the
            // normal "thank you / order in progress" page; the order updates moments later.
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_COMPLETE,
            );

            return;
        }

        // We don't have a forced status, so we retrieve the payment status from PayPlug
        $client = $this->apiClientFactory->createForPaymentMethod($method);
        /** @var string|null $payplugPaymentId */
        $payplugPaymentId = $payment->getDetails()['payment_id'] ?? null;
        if (null === $payplugPaymentId) {
            $this->logger->warning('No PayPlug payment ID found in payment details.', ['payment_id' => $payment->getId(), 'order_id' => $payment->getOrder()?->getId()]);
            $payment->setDetails(['status' => PayPlugApiClientInterface::FAILED]);
            $this->paymentTransitionApplier->apply($payment);

            return;
        }

        $payplugPayment = $client->retrieve($payplugPaymentId);

        $paymentRequest->setResponseData((array) $payplugPayment);
        $details = new \ArrayObject($payment->getDetails());
        $this->paymentNotificationHandler->treat($payment, $payplugPayment, $details);

        $payment->setDetails($details->getArrayCopy());
        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED) {
            // If is already completed, do not try to update it again (updated by notification)
            $this->paymentTransitionApplier->apply($payment);
        }

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
        $previousDetails = $payment->getDetails();

        $payment->setDetails([
            ...$previousDetails,
            'status' => $statusPaymentRequest->getForcedStatus(),
        ]);

        if (!$this->paymentTransitionApplier->apply($payment)) {
            $payment->setDetails($previousDetails);
        }

        // Mark the PaymentRequest as completed
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
