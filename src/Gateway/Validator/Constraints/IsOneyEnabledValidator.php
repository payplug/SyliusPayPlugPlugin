<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClient;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class IsOneyEnabledValidator extends ConstraintValidator
{
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
            $checker = new OneyChecker(new PayPlugApiClient($value));

            if (false === $checker->isEnabled()) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();
            }
        } catch (UnauthorizedException $exception) {
            // do nothing, this should be handle by IsPayPlugSecretKeyValid Constraint
            return;
        }
    }
}
