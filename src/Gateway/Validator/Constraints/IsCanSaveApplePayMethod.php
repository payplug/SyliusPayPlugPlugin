<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

/**
 * @Annotation
 */
final class IsCanSaveApplePayMethod extends IsCanSavePaymentMethod
{
    public string $noTestKeyMessage = 'payplug_sylius_payplug_plugin.apple_pay.can_not_save_method_with_test_key';

    public string $noAccessMessage = 'payplug_sylius_payplug_plugin.apple_pay.can_not_save_method_no_access';
}
