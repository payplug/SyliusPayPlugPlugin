<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsScalapayAmountRangeValid extends Constraint
{
    public string $minGreaterThanMaxMessage = 'payplug_sylius_payplug_plugin.payplug_scalapay.min_amount_greater_than_max';

    public string $outOfRangeMessage = 'payplug_sylius_payplug_plugin.payplug_scalapay.amount_out_of_authorized_range';

    public function validatedBy(): string
    {
        return IsScalapayAmountRangeValidValidator::class;
    }
}
