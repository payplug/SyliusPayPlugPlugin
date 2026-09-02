<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayPlug\SyliusPayPlugPlugin\Command\PaymentCaptureFlow;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * Shared by CaptureHostedPaymentRequestHandler and CaptureAliasPaymentRequestHandler, the two
 * sibling capture paths that fail or apply the outcome of a Unified API payment creation call the
 * same way, differing only in what precedes it.
 */
final class PaymentCaptureOutcomeApplier
{
    public function __construct(
        private LoggerInterface $logger,
        private StateMachineInterface $stateMachine,
        private IOrderStateMutator $orderStateMutator,
    ) {
    }

    public function failPaymentRequest(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        \Throwable $e,
        PaymentCaptureFlow $flow,
    ): void {
        $this->logger->error(\sprintf('[PayPlug][UPC] %s payment creation failed.', $flow->value), [
            'sylius_payment_id' => $payment->getId(),
            'error' => $e->getMessage(),
        ]);
        $paymentRequest->setResponseData(['error' => $e->getMessage()]);
        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    public function applyOutcome(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PaymentOutput $output,
    ): void {
        if (null !== $output->redirectHtml) {
            // The "recommended for web" 3DS-pending shape — an auto-submitting HTML form the
            // browser must render directly (see CaptureHttpResponseProvider). This is what the
            // Unified API actually returns by default; redirectUrl only appears when the request
            // explicitly set card.threeDSecure.displayMode=raw, which this plugin never does.
            $paymentRequest->setResponseData(['redirect_html' => $output->redirectHtml]);

            return;
        }

        if (null !== $output->redirectUrl) {
            $paymentRequest->setResponseData(['redirect_url' => $output->redirectUrl]);

            return;
        }

        $paymentRequest->setResponseData(['status' => $output->status]);

        // No 3DS redirect means the outcome is already known synchronously — apply it to the
        // actual Sylius Payment right away instead of waiting on the async webhook, which may
        // be delayed or, in this test environment, never arrive at all. SyliusOrderStateMutator
        // is idempotent (checks the state machine before transitioning), so it's safe to also
        // run again if/when the webhook (e.g. NotifyHostedPaymentRequestHandler) eventually shows up.
        $responseBody = \json_decode($output->body, true);
        $execCode = \is_array($responseBody) ? ($responseBody['execCode'] ?? null) : null;
        if (\is_string($execCode)) {
            $this->orderStateMutator->apply(ResourceIdentifier::toString($payment->getId()), ExecCodeMapper::toPaymentOutcome($execCode));
        }
    }
}
