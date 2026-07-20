<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Spike;

use Doctrine\ORM\EntityManagerInterface;
use PayplugUnifiedCore\Contracts\IOrderStateMutator;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\PaymentOutcome;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * PRE-3469 spike: proof-of-concept implementation of IOrderStateMutator against Sylius's
 * Symfony Workflow-backed payment state machine — not shipped code.
 *
 * Friction found (confirmed by the level-3 integration test, not just by reading): the state
 * machine's `apply()` only mutates the in-memory `state` property (marking_store: method) — it
 * never flushes. Without an explicit EntityManager::flush() the transition is silently lost the
 * moment the request ends. The plugin's existing PaymentStateResolver::resolve() already flushes
 * for the same reason; this skeleton was missing it until the integration test caught it.
 *
 * Second friction found: IOrderStateMutator::apply() is keyed by order ID, but the Symfony
 * Workflow transition actually lives on the order's Payment sub-entity (`sylius_payment` graph),
 * not on the Order itself. This adds one extra hop (Order -> getLastPayment()) that a
 * WooCommerce implementation, whose native order carries the payment status directly, would not
 * need. Not blocking: the interface deliberately stays CMS-agnostic, so this hop belongs in the
 * Sylius adapter, not in the contract.
 */
final class SyliusOrderStateMutator implements IOrderStateMutator
{
    /**
     * THREE_DS_PENDING is deliberately absent: per PRE-3469, the order must stay in its
     * current state (`new`) until the async webhook resolves it to PAID/AUTHORIZED/FAILED —
     * there is no Symfony Workflow transition for "awaiting 3DS".
     *
     * @var array<string, string>
     */
    private const OUTCOME_TO_TRANSITION = [
        PaymentOutcome::PAID => PaymentTransitions::TRANSITION_COMPLETE,
        PaymentOutcome::AUTHORIZED => PaymentTransitions::TRANSITION_AUTHORIZE,
        PaymentOutcome::CAPTURE_REQUIRED => PaymentTransitions::TRANSITION_PROCESS,
        PaymentOutcome::REFUNDED => PaymentTransitions::TRANSITION_REFUND,
        PaymentOutcome::FAILED => PaymentTransitions::TRANSITION_FAIL,
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(string $orderId, string $outcome): void
    {
        if (PaymentOutcome::THREE_DS_PENDING === $outcome) {
            return;
        }

        $transition = self::OUTCOME_TO_TRANSITION[$outcome] ?? null;
        if (null === $transition) {
            throw new \InvalidArgumentException(\sprintf('No Symfony Workflow transition mapped for PaymentOutcome "%s".', $outcome));
        }

        $order = $this->orderRepository->findOrderById($orderId);
        if (null === $order) {
            throw new PaymentNotFoundException(\sprintf('No order found for id "%s".', $orderId));
        }

        $payment = $order->getLastPayment();
        if (null === $payment) {
            throw new PaymentNotFoundException(\sprintf('Order "%s" has no payment to mutate.', $orderId));
        }

        // Guarded exactly like the plugin's existing PaymentStateResolver::applyTransition():
        // an out-of-order webhook retry landing on a payment already past this transition is a
        // silent no-op rather than a thrown workflow exception.
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
            $this->entityManager->flush();
        }
    }
}
