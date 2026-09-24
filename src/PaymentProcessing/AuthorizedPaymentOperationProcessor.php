<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\Exception\Payment\AuthorizationOperationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationOperatorInterface;
use PayPlug\SyliusPayPlugPlugin\Upc\PaymentOrderIdResolver;
use PayplugUnifiedCore\Contracts\ILock;
use PayplugUnifiedCore\Exceptions\AmountExceedsAvailableException;
use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Exceptions\CancellationAmountException;
use PayplugUnifiedCore\Exceptions\CaptureAmountException;
use PayplugUnifiedCore\Exceptions\CardOperationException;
use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\OperationConflictException;
use PayplugUnifiedCore\Exceptions\PartialCancellationNotAllowedException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCancelledException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCapturedException;
use PayplugUnifiedCore\Exceptions\PaymentNotCapturableException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\PaymentNotVoidableException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Captures (fully, partially, several times) and cancels (fully or partially) the authorization
 * behind a deferred-capture Hosted Fields payment, from the admin order screen, then keeps the
 * Sylius payment state in step with it:
 *  - partial capture, or partial cancellation: stays "authorized", something is still capturable;
 *  - nothing left capturable after a capture: "completed" (the order becomes paid);
 *  - nothing left capturable after a cancellation: "cancelled". A cancellation is only offered
 *    before any capture, so a cancellation never has captured money behind it.
 *
 * Every lifecycle rule the Unified API enforces (expiry, amounts, contract options) stays UPC's:
 * the local checks below only avoid offering — or sending — an operation the recorded state
 * already rules out, and any refusal from UPC is surfaced as an AuthorizationOperationException
 * without recording anything or applying any transition, so the payment is never left in a state
 * that doesn't match the transaction.
 *
 * Guarded by $lock per payment plus AuthorizationDetails::version(): UPC's capture/cancel calls
 * take no idempotency key, so a double click or a replayed form would otherwise move real money
 * twice. The lock serializes concurrent submissions; the version, checked once the lock is held,
 * rejects a submission built against a state that has since moved on.
 */
final class AuthorizedPaymentOperationProcessor
{
    private const LOCK_TTL_SECONDS = 30;

    private const ERROR_KEY_PREFIX = 'payplug_sylius_payplug_plugin.admin.authorization.error.';

    public function __construct(
        private AuthorizationOperatorInterface $authorizationOperator,
        private ILock $lock,
        private StateMachineInterface $stateMachine,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether $payment is one this processor manages at all: a Hosted Fields payment opened as an
     * authorization only. Everything else (legacy SDK deferred capture included) is left to the
     * flows that already handle it.
     */
    public static function supports(PaymentInterface $payment): bool
    {
        $method = $payment->getMethod();

        return $method instanceof PaymentMethodInterface &&
            PayPlugGatewayFactory::isHostedFieldsConfig($method->getGatewayConfig()) &&
            AuthorizationDetails::fromDetails($payment->getDetails())->isDeferred();
    }

    public function canCapture(PaymentInterface $payment): bool
    {
        $authorization = AuthorizationDetails::fromDetails($payment->getDetails());

        return $this->isAuthorized($payment) &&
            !$authorization->isExpired($this->clock->now()) &&
            $authorization->remainingAmount() > 0;
    }

    public function canCancel(PaymentInterface $payment): bool
    {
        return $this->canCapture($payment) && !AuthorizationDetails::fromDetails($payment->getDetails())->hasCaptures();
    }

    /**
     * @param int|null $amount null captures everything still capturable
     * @param int|null $expectedVersion the AuthorizationDetails::version() the caller's form was
     *                                  built against; null skips the replay check (programmatic use)
     *
     * @throws AuthorizationOperationException
     */
    public function capture(PaymentInterface $payment, ?int $amount, ?int $expectedVersion = null): void
    {
        $this->runLocked($payment, function () use ($payment, $amount, $expectedVersion): void {
            $this->assertOperable($payment, $expectedVersion, AuthorizationDetails::OPERATION_CAPTURE);
            $this->captureRecorded($payment, $amount);

            if (0 === AuthorizationDetails::fromDetails($payment->getDetails())->remainingAmount()) {
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);
            }
        });
    }

    /**
     * @param int|null $amount null cancels everything still authorized
     *
     * @throws AuthorizationOperationException
     */
    public function cancel(PaymentInterface $payment, ?int $amount, ?int $expectedVersion = null): void
    {
        $this->runLocked($payment, function () use ($payment, $amount, $expectedVersion): void {
            $this->assertOperable($payment, $expectedVersion, AuthorizationDetails::OPERATION_CANCELLATION);

            $authorization = AuthorizationDetails::fromDetails($payment->getDetails());
            $remaining = $authorization->remainingAmount();
            $amount = $this->resolveAmount($payment, $amount, $remaining);

            try {
                $output = $this->authorizationOperator->cancel(
                    $this->resolveMethod($payment),
                    $this->resolveUnifiedApiPaymentId($payment),
                    PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId()),
                    // Without an amount only on an untouched authorization: an explicit amount is
                    // what UPC reads as a partial cancellation, which the merchant's contract may
                    // not allow even when it equals the whole remainder. Once part of it has been
                    // cancelled, "no amount" would target the original authorization again, so the
                    // exact remainder is sent instead.
                    $amount === $remaining && 0 === $authorization->version() ? null : $amount,
                    $authorization->version() + 1,
                    $payment->getCurrencyCode(),
                );
            } catch (PayplugException $exception) {
                throw $this->refusal($payment, AuthorizationDetails::OPERATION_CANCELLATION, $exception);
            }

            $this->record($payment, AuthorizationDetails::OPERATION_CANCELLATION, $output->body, $amount);

            if ($amount === $remaining) {
                $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);
            }
        });
    }

    /**
     * Keeps Sylius's own "complete" transition meaningful for a deferred Hosted Fields payment:
     * applied while money is still only authorized — by the native admin "Complete" button, by
     * payplug:capture-authorized-payments, or by a merchant's own shipping listener (see
     * doc/authorized_payment.md) — it first captures whatever is still capturable. Throwing here
     * aborts the transition, so the payment never reads "completed" without the capture behind it.
     *
     * A no-op when this processor applies "complete" itself after the last capture, since nothing
     * is left capturable by then.
     */
    #[AsTransitionListener(workflow: PaymentTransitions::GRAPH, transition: PaymentTransitions::TRANSITION_COMPLETE)]
    public function onCompleteTransition(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface || !self::supports($payment) || !$this->isAuthorized($payment)) {
            return;
        }

        if (0 === AuthorizationDetails::fromDetails($payment->getDetails())->remainingAmount()) {
            return;
        }

        $this->runLocked($payment, function () use ($payment): void {
            $this->assertOperable($payment, null, AuthorizationDetails::OPERATION_CAPTURE);
            $this->captureRecorded($payment, null);
        });
    }

    /**
     * @throws AuthorizationOperationException
     */
    private function captureRecorded(PaymentInterface $payment, ?int $amount): void
    {
        $remaining = AuthorizationDetails::fromDetails($payment->getDetails())->remainingAmount();
        $amount = $this->resolveAmount($payment, $amount, $remaining);

        try {
            $output = $this->authorizationOperator->capture(
                $this->resolveMethod($payment),
                $this->resolveUnifiedApiPaymentId($payment),
                PaymentOrderIdResolver::resolve($payment->getOrder(), $payment->getId()),
                // Always explicit: without an amount the Unified API captures the ORIGINAL
                // authorized amount, not what remains — after a partial capture that is refused as
                // a duplicate (execCode 4011, staging 2026-09-24).
                $amount,
                $payment->getCurrencyCode(),
                AuthorizationDetails::fromDetails($payment->getDetails())->version() + 1,
            );
        } catch (PayplugException $exception) {
            throw $this->refusal($payment, AuthorizationDetails::OPERATION_CAPTURE, $exception);
        }

        $this->record($payment, AuthorizationDetails::OPERATION_CAPTURE, $output->body, $amount);

        if (null !== $output->maxCaptureDate) {
            $payment->setDetails([...$payment->getDetails(), AuthorizationDetails::MAX_CAPTURE_DATE => $output->maxCaptureDate]);
        }
    }

    /**
     * @throws AuthorizationOperationException
     */
    private function assertOperable(PaymentInterface $payment, ?int $expectedVersion, string $operation): void
    {
        if (!self::supports($payment) || !$this->isAuthorized($payment)) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'not_authorized');
        }

        $authorization = AuthorizationDetails::fromDetails($payment->getDetails());

        if (null !== $expectedVersion && $expectedVersion !== $authorization->version()) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'stale');
        }

        if ($authorization->isExpired($this->clock->now())) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'expired');
        }

        if (AuthorizationDetails::OPERATION_CANCELLATION === $operation && $authorization->hasCaptures()) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'cancel_after_capture');
        }

        if (0 === $authorization->remainingAmount()) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'nothing_remaining');
        }
    }

    /**
     * @throws AuthorizationOperationException
     */
    private function resolveAmount(PaymentInterface $payment, ?int $amount, int $remaining): int
    {
        $amount ??= $remaining;

        if ($amount <= 0) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'invalid_amount');
        }

        if ($amount > $remaining) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'amount_exceeds_remaining', [
                '%remaining%' => self::formatAmount($remaining, $payment->getCurrencyCode()),
            ]);
        }

        return $amount;
    }

    /**
     * The operation id is what lets HostedFieldsWebhookNotificationHandler recognize this
     * operation's own webhook later on (and UnifiedApiIpnAction resolve the payment for it at
     * all). Its absence is logged rather than failing the call: the operation did happen, and
     * refusing to record it would leave the payment out of step with the transaction.
     */
    private function record(PaymentInterface $payment, string $operation, string $responseBody, int $amount): void
    {
        $operationId = self::extractFirstOperationId($responseBody);
        if (null === $operationId) {
            $this->logger->error('[PayPlug][UPC] Authorization operation succeeded but the response carried no operationIds.', [
                'sylius_payment_id' => $payment->getId(),
                'operation' => $operation,
                'response_body' => $responseBody,
            ]);
        }

        $payment->setDetails(AuthorizationDetails::withOperation($payment->getDetails(), $operation, $operationId, $amount));

        $this->logger->info('[PayPlug][UPC] Authorization operation recorded.', [
            'sylius_payment_id' => $payment->getId(),
            'operation' => $operation,
            'operation_id' => $operationId,
            'amount' => $amount,
        ]);
    }

    private function refusal(
        PaymentInterface $payment,
        string $operation,
        PayplugException $exception,
    ): AuthorizationOperationException
    {
        $this->logger->error('[PayPlug][UPC] Authorization operation refused.', [
            'sylius_payment_id' => $payment->getId(),
            'operation' => $operation,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        $reason = match (true) {
            $exception instanceof AuthorizationExpiredException => 'expired',
            $exception instanceof AmountExceedsAvailableException,
            $exception instanceof CaptureAmountException,
            $exception instanceof CancellationAmountException => 'amount_refused',
            $exception instanceof PaymentAlreadyCapturedException => 'already_captured',
            $exception instanceof PaymentAlreadyCancelledException => 'already_cancelled',
            // Same message from the API whether the payment was already captured or cancelled.
            $exception instanceof PaymentNotCapturableException,
            $exception instanceof PaymentNotVoidableException => 'not_operable',
            $exception instanceof MultipleCaptureNotAllowedException => 'multiple_capture_not_allowed',
            $exception instanceof PartialCancellationNotAllowedException => 'partial_cancellation_not_allowed',
            $exception instanceof OperationConflictException => 'conflict',
            $exception instanceof CardOperationException => 'refused_by_issuer',
            $exception instanceof PaymentNotFoundException => 'payment_not_found',
            default => 'api_error',
        };

        return new AuthorizationOperationException(self::ERROR_KEY_PREFIX . $reason, [], $exception);
    }

    /**
     * @throws AuthorizationOperationException
     */
    private function runLocked(PaymentInterface $payment, \Closure $operation): void
    {
        $lockKey = AuthorizationDetails::lockKey($payment->getId());
        if (!$this->lock->acquire($lockKey, self::LOCK_TTL_SECONDS)) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'in_progress');
        }

        try {
            $operation();
        } finally {
            $this->lock->release($lockKey);
        }
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->logger->warning('[PayPlug][UPC] Cannot apply payment transition after an authorization operation.', [
                'sylius_payment_id' => $payment->getId(),
                'current_state' => $payment->getState(),
                'transition' => $transition,
            ]);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
    }

    private function isAuthorized(PaymentInterface $payment): bool
    {
        return PaymentInterface::STATE_AUTHORIZED === $payment->getState();
    }

    private function resolveMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        return $method;
    }

    private function resolveUnifiedApiPaymentId(PaymentInterface $payment): string
    {
        $paymentId = $payment->getDetails()['hosted_fields_payment_id'] ?? null;
        if (!\is_string($paymentId) || '' === $paymentId) {
            throw new AuthorizationOperationException(self::ERROR_KEY_PREFIX . 'payment_not_found');
        }

        return $paymentId;
    }

    private static function extractFirstOperationId(string $body): ?string
    {
        $decoded = \json_decode($body, true);
        $operationIds = \is_array($decoded) ? ($decoded['operationIds'] ?? null) : null;
        $operationId = \is_array($operationIds) ? ($operationIds[0] ?? null) : null;

        return \is_string($operationId) && '' !== $operationId ? $operationId : null;
    }

    private static function formatAmount(int $amount, ?string $currencyCode): string
    {
        return \trim(\number_format($amount / 100, 2, '.', ' ') . ' ' . ($currencyCode ?? ''));
    }
}
