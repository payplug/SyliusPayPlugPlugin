<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 *
 * @deprecated Use PayplugPermission constraint instead
 */
final class IsCanSaveCards extends Constraint
{
    /** @var string */
    public $message = 'payplug_sylius_payplug_plugin.one_click.can_not_save_cards';

    public function validatedBy(): string
    {
        return IsCanSaveCardsValidator::class;
    }
}
