<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * PRE-3628's per-channel rule, as a constraint rather than only a form listener.
 *
 * `PaymentMethodTypeExtension` enforces it on the admin form, which is where a merchant meets it.
 * This constraint widens that to writes carrying no form — but only as far as
 * `PaymentMethodValidator::process()` reaches, which is less than it sounds: its single caller is
 * `PostSavePaymentMethodEventListener::onUpdate()`, and only on the branch where `renew_oauth` is
 * not set.
 *
 * So the rule is *not* enforced on:
 *  - resource-controller **creates** — `onCreate()` only calls `startOAuth()`;
 *  - updates that renew OAuth credentials, which take the `startOAuth()` branch;
 *  - anything bypassing the resource controller entirely (fixtures, console commands, a direct
 *    `$paymentMethod->enable()`).
 *
 * A conflicting config created through the admin API is therefore caught on its next ordinary
 * update rather than at creation. This is a pre-existing gap — the `canBeCreated()` check that
 * preceded PRE-3628 had the identical hole — and closing it properly means a class-level
 * constraint on `PaymentMethodInterface` in the resource's validation config, not another hook
 * here. Until then the invariant is a strong default, not a guarantee: read it as such wherever
 * code assumes at most one enabled gateway per factory per channel.
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
