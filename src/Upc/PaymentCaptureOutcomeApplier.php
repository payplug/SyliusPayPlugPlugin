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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Shared by CaptureHostedPaymentRequestHandler and CaptureAliasPaymentRequestHandler, the two
 * sibling capture paths that fail or apply the outcome of a Unified API payment creation call the
 * same way, differing only in what precedes it.
 */
final class PaymentCaptureOutcomeApplier
{
    // Deliberately generic, and deliberately NOT the caught exception's own message: that text
    // comes straight from the Unified API and routinely describes our own infrastructure or the
    // merchant's account configuration (a 403 reads "The IP address "10.x.x.x" is not allowed to
    // access this account."). The detail stays in the log and in the PaymentRequest's
    // response_data; the shopper only needs to know the payment didn't go through and their card
    // wasn't charged. Not the legacy error.transaction_failed_1click key, whose wording is
    // identical but whose name only fits the one-click flow — this applier serves both capture
    // paths.
    private const SHOPPER_ERROR_FLASH_KEY = 'payplug_sylius_payplug_plugin.error.transaction_failed';

    public function __construct(
        private LoggerInterface $logger,
        private StateMachineInterface $stateMachine,
        private IOrderStateMutator $orderStateMutator,
        private RequestStack $requestStack,
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
        $this->notifyShopper();
        $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    /**
     * Without this the capture failure is entirely silent to the customer: the Payment stays
     * "new" and the order "awaiting_payment" (both intentional — the payment is still
     * retryable), so they are simply redirected to the order summary with no indication that
     * anything went wrong.
     *
     * A missing session is not an error to report: this runs from the CLI
     * (UpdatePaymentStateCommand) and from worker contexts too, where Request::getSession()
     * throws. There is no shopper to tell in those cases, and a failed payment must not turn
     * into a 500 because there was nowhere to put the message.
     */
    private function notifyShopper(): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add('error', self::SHOPPER_ERROR_FLASH_KEY);
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
