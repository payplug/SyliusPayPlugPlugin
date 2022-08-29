<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsCanSaveBancontactMethod extends Constraint
{
    /** @var string */
    public $noTestKeyMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_with_test_key';

    /** @var string */
    public $noAccessMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_no_access';

    public function validatedBy(): string
    {
        return IsCanSaveBancontactMethodValidator::class;
    }
}
