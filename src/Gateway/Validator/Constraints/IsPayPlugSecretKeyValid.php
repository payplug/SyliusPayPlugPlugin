<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsPayPlugSecretKeyValid extends Constraint
{
    /** @var string */
    public $message = 'payplug_sylius_payplug_plugin.secret_key.not_valid';

    public function validatedBy(): string
    {
        return IsPayPlugSecretKeyValidator::class;
    }
}
