<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * PRE-3628's per-channel rule, as a constraint rather than only a form listener.
 *
 * `PaymentMethodTypeExtension` enforces it on the admin form, which is where a merchant meets it.
 * This constraint is the backstop for every other write that reaches
 * `PaymentMethodValidator::process()` — notably the Sylius resource controller, and so the admin
 * API — where no form listener runs and two enabled gateways of one factory could otherwise end up
 * sharing a channel, silently breaking the invariant the whole multi-shop design rests on.
 *
 * @Annotation
 */
final class HasNoGatewayChannelConflict extends Constraint
{
    /** @var string */
    public $message = 'payplug_sylius_payplug_plugin.form.gateway_channel_conflict';

    public function validatedBy(): string
    {
        return HasNoGatewayChannelConflictValidator::class;
    }
}
