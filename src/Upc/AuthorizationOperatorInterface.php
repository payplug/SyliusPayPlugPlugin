<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Exceptions\PayplugException;
use PayplugUnifiedCore\Output\CancellationOutput;
use PayplugUnifiedCore\Output\CaptureOutput;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Sylius-side port onto UPC's capturePayment()/cancelPayment(). $method identifies which Hosted
 * Fields account the authorization belongs to — see RefundCreatorInterface for why that must be
 * the payment's own method rather than any Hosted-Fields-configured one.
 *
 * A null $amount asks for the full remaining amount; UPC owns every lifecycle rule beyond that
 * and reports a refusal through one of its PayplugException subtypes.
 *
 * $sequence numbers the operation among all captures/cancellations of the same authorization and
 * goes into its description: the Unified API refuses a second operation carrying the same
 * orderId/description as an earlier one as a duplicate (execCode 4011, staging 2026-09-24).
 */
interface AuthorizationOperatorInterface
{
    /**
     * @throws PayplugException
     */
    public function capture(
        PaymentMethodInterface $method,
        string $paymentId,
        string $orderId,
        ?int $amount,
        ?string $currency = null,
        int $sequence = 1,
    ): CaptureOutput;

    /**
     * @throws PayplugException
     */
    public function cancel(
        PaymentMethodInterface $method,
        string $paymentId,
        string $orderId,
        ?int $amount,
        int $sequence = 1,
        ?string $currency = null,
    ): CancellationOutput;
}
