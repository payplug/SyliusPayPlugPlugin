<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsNotCombiningIntegratedPaymentAndHostedFields extends Constraint
{
    /** @var string */
    public $message = 'payplug_sylius_payplug_plugin.payplug.integrated_payment_and_hosted_fields_conflict';

    public function validatedBy(): string
    {
        return IsNotCombiningIntegratedPaymentAndHostedFieldsValidator::class;
    }
}
