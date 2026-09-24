<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AuthorizedPaymentOperationProcessor;
use PayPlug\SyliusPayPlugPlugin\Upc\AuthorizationDetails;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Feeds the admin order screen's deferred-capture block
 * (templates/admin/order/show/authorization.html.twig): which actions to offer, and the amounts and
 * deadline to show — always derived from the same AuthorizedPaymentOperationProcessor rules the
 * controller enforces, so the screen never offers what the controller would refuse.
 */
final class AuthorizationExtension extends AbstractExtension
{
    public function __construct(
        private AuthorizedPaymentOperationProcessor $processor,
        private ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payplug_authorization', $this->describe(...)),
        ];
    }

    /**
     * @return array{
     *     authorized_amount: int,
     *     captured_amount: int,
     *     cancelled_amount: int,
     *     remaining_amount: int,
     *     max_capture_date: \DateTimeImmutable|null,
     *     expired: bool,
     *     expiring_soon: bool,
     *     can_capture: bool,
     *     can_cancel: bool,
     *     version: int,
     * }|null null for any payment that isn't a deferred-capture Hosted Fields one
     */
    public function describe(PaymentInterface $payment): ?array
    {
        if (!AuthorizedPaymentOperationProcessor::supports($payment)) {
            return null;
        }

        $authorization = AuthorizationDetails::fromDetails($payment->getDetails());
        $now = $this->clock->now();

        return [
            'authorized_amount' => $authorization->authorizedAmount(),
            'captured_amount' => $authorization->capturedAmount(),
            'cancelled_amount' => $authorization->cancelledAmount(),
            'remaining_amount' => $authorization->remainingAmount(),
            'max_capture_date' => $authorization->maxCaptureDate(),
            'expired' => $authorization->isExpired($now),
            'expiring_soon' => $authorization->isExpiringSoon($now),
            'can_capture' => $this->processor->canCapture($payment),
            'can_cancel' => $this->processor->canCancel($payment),
            'version' => $authorization->version(),
        ];
    }
}
