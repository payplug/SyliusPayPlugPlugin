<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsCanSaveBancontactMethod extends Constraint
{
    public string $noTestKeyMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_with_test_key';
    
    public string $noAccessMessage = 'payplug_sylius_payplug_plugin.bancontact.can_not_save_method_no_access';

    public function validatedBy(): string
    {
        return IsCanSaveBancontactMethodValidator::class;
    }
}
