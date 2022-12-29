<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
final class IsCanSavePaymentMethod extends Constraint
{
    public string $noTestKeyMessage = '';

    public string $noAccessMessage = '';

    public function validatedBy(): string
    {
        return IsCanSavePaymentMethodValidator::class;
    }
}
