<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @Annotation
 */
final class IsPayPlugSecretKeyValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsPayPlugSecretKeyValid) {
            throw new UnexpectedTypeException($constraint, IsPayPlugSecretKeyValid::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        try {
            \Payplug\Payplug::init(['secretKey' => $value]);
            \Payplug\Authentication::getPermissions();
        } catch (UnauthorizedException $exception) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
