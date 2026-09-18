<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\IConfigurationRepository;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * UPC's {@see IConfigurationRepository} contract takes no context on any of its methods — it was
 * written for a CMS with one PayPlug account per installation. Since PRE-3628 a Sylius merchant may
 * have several enabled CB gateway configs, one per channel, so "the" account is no longer a single
 * thing and the reader has to be told which one it is looking at.
 *
 * Rather than widen UPC's shared contract (a breaking change to a package other plugins consume,
 * for a Sylius-only problem), the scope is carried on the Sylius side by this sub-interface. The
 * wither returns a new instance rather than mutating: the repository is registered as a shared
 * service, and a mutable scope on it would leak across requests — IPN and background token refresh
 * being exactly where that would go unnoticed.
 */
interface ScopedConfigurationRepositoryInterface extends IConfigurationRepository
{
    /**
     * Returns a copy scoped to $gatewayConfig. The receiver is left untouched.
     */
    public function withGatewayConfig(GatewayConfigInterface $gatewayConfig): self;

    /**
     * Convenience for the common case — every consumer holds a payment method rather than a bare
     * gateway config.
     *
     * @throws \LogicException if $paymentMethod has no gateway config
     */
    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): self;
}
