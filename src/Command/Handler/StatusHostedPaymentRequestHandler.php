<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command\Handler;

use PayPlug\SyliusPayPlugPlugin\Command\StatusHostedPaymentRequest;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StatusHostedPaymentRequestHandler
{
    private const FORCED_STATUS_CANCELED = 'canceled';

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(StatusHostedPaymentRequest $statusHostedPaymentRequest): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($statusHostedPaymentRequest);

        if (self::FORCED_STATUS_CANCELED === $statusHostedPaymentRequest->getForcedStatus()) {
            $payment = $paymentRequest->getPayment();
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
            }
        }

        // No polling against any API: the Payment's current state is whatever the Notify handler
        // (Task 10) has already applied from the webhook, or still "processing" if none has
        // arrived yet — this handler never queries UPC/PayPlug for a status.
        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }
}
