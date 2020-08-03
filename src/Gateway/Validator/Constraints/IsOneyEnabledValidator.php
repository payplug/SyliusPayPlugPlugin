<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class IsOneyEnabledValidator extends ConstraintValidator
{
    private const PERMISSION_ONEY_FIELD = 'can_use_oney';

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsOneyEnabled) {
            throw new UnexpectedTypeException($constraint, IsOneyEnabled::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        try {
            \Payplug\Payplug::init(['secretKey' => $value]);
            $permissions = \Payplug\Authentication::getPermissions() ?? [];

            if (!\array_key_exists(self::PERMISSION_ONEY_FIELD, $permissions) ||
                $permissions[self::PERMISSION_ONEY_FIELD] !== true) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();
            }
        } catch (UnauthorizedException $exception) {
            // do nothing, this should be handle by IsPayPlugSecretKeyValid Constraint
            return;
        }
    }
}
