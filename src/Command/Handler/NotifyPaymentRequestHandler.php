<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Command\NotifyPaymentRequest;
use PayPlug\SyliusPayPlugPlugin\Handler\PaymentNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\Handler\RefundNotificationHandler;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\PaymentTransitionApplier;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Models\PaymentOutcome;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyPaymentRequestHandler
{
    /**
     * PRE-3469: additive translation from the PayPlug status vocabulary (the same one
     * PaymentTransitionApplier already maps from) to UPC's PaymentOutcome vocabulary, for the
     * real IOrderStateMutator call below. Statuses with no PaymentOutcome equivalent
     * (e.g. STATUS_ABORTED/STATUS_CANCELED*, which PaymentTransitionApplier maps to
     * TRANSITION_CANCEL) are intentionally absent here — they're skipped, never force-mapped.
     *
     * @var array<string, string>
     */
    private const STATUS_TO_OUTCOME = [
        PayPlugApiClientInterface::STATUS_CAPTURED => PaymentOutcome::PAID,
        PayPlugApiClientInterface::STATUS_AUTHORIZED => PaymentOutcome::AUTHORIZED,
        PayPlugApiClientInterface::FAILED => PaymentOutcome::FAILED,
    ];

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private PaymentNotificationHandler $paymentNotificationHandler,
        private RefundNotificationHandler $refundNotificationHandler,
        private PaymentTransitionApplier $paymentTransitionApplier,
        private IOrderStateMutator $orderStateMutator,
        private LoggerInterface $logger,
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
                $this->applyOrderStateMutator($payment);
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

    /**
     * PRE-3469: additive real call site for PayplugOrderStateMutator. PaymentTransitionApplier
     * has already applied the real transition above by the time this runs, so the mutator's own
     * can()-guard makes this a no-op in the normal case — this exists to prove the contract works
     * against a live webhook event, not to change behavior. Any failure here is caught and
     * logged, never allowed to affect the primary notification flow above.
     */
    private function applyOrderStateMutator(PaymentInterface $payment): void
    {
        $details = $payment->getDetails(); // @phpstan-ignore-line - getDetails() return mixed
        $status = $details['status'] ?? '';
        $outcome = self::STATUS_TO_OUTCOME[$status] ?? null;
        if (null === $outcome) {
            return;
        }

        $order = $payment->getOrder();
        if (null === $order) {
            return;
        }

        // PayplugOrderStateMutator resolves the order's *last* payment internally (its contract
        // is keyed by order ID, not payment ID). On a multi-payment order — e.g. a failed attempt
        // followed by a retry — that could be a different payment than the one this webhook is
        // actually about. Skip rather than risk transitioning the wrong payment.
        if ($order->getLastPayment()?->getId() !== $payment->getId()) {
            return;
        }

        try {
            $this->orderStateMutator->apply((string) $order->getId(), $outcome); // @phpstan-ignore-line - ResourceInterface::getId() return mixed
        } catch (\Throwable $e) {
            $this->logger->warning('[PayPlug] PayplugOrderStateMutator additive call failed.', [
                'sylius_payment_id' => $payment->getId(),
                'outcome' => $outcome,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
