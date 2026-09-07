<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * The single implementation of this resolution rule — used directly by UnifiedApiRefundCreator,
 * and by PaymentCaptureContextBuilder::resolveGatewayCredentials() (a thin instance-method
 * delegate kept there so its own two capture-handler callers, already holding a
 * PaymentCaptureContextBuilder for everything else they need, don't have to depend on this class
 * too). Standalone and static rather than folded only into PaymentCaptureContextBuilder, since
 * that class also requires dependencies (URL generation, address mapping) a refund has no use for
 * — pulling the whole thing into UnifiedApiRefundCreator just to reuse this one guard clause would
 * be worse than the small indirection this class adds instead.
 */
final class GatewayCredentialsResolver
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Resolved from $method's own gateway config specifically — a merchant with more than one
     * Hosted-Fields-configured payment method must have each payment/refund routed to the account
     * it actually belongs to, never "whichever Hosted Fields config happens to match first."
     *
     * Returns the account id alone. A submerchantExternalId belongs to the UDV/MID configuration
     * for the payment's currency — the EUR ones carry one, the other-currency ones do not — and
     * this plugin's Hosted Fields flow targets the multi-currency configurations (EUR is served by
     * Integrated Payment instead). So there is never one to contribute here, and UPC omits that key
     * entirely when none is supplied.
     */
    public static function resolve(PaymentMethodInterface $method): string
    {
        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $accountId = $gatewayConfig[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null;
        if (!\is_string($accountId) || '' === $accountId) {
            throw new \LogicException('Hosted Fields account id is not configured for this payment method.');
        }

        return $accountId;
    }
}
