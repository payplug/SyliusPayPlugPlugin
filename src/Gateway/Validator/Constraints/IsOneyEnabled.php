<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsOneyEnabled extends Constraint
{
    /** @var string */
    public $message = 'payplug_sylius_payplug_plugin.oney.not_enabled';

    public function validatedBy(): string
    {
        return IsOneyEnabledValidator::class;
    }
}
