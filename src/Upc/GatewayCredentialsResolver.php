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
     * @return array{0: string, 1: string} accountId, submerchantExternalId
     */
    public static function resolve(PaymentMethodInterface $method): array
    {
        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $accountId = $gatewayConfig[PayPlugGatewayFactory::HF_IDENTIFIER] ?? null;
        $submerchantExternalId = $gatewayConfig[PayPlugGatewayFactory::HF_SUB_MERCHANT_ID] ?? null;
        if (!\is_string($accountId) || '' === $accountId || !\is_string($submerchantExternalId) || '' === $submerchantExternalId) {
            throw new \LogicException('Hosted Fields account id or submerchant id is not configured for this payment method.');
        }

        return [$accountId, $submerchantExternalId];
    }
}
