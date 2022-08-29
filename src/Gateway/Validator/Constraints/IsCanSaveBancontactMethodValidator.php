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
final class IsCanSaveBancontactMethodValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsCanSaveBancontactMethod) {
            throw new UnexpectedTypeException($constraint, IsCanSaveBancontactMethod::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $secretKey = $this->context->getRoot()->getData()->getGatewayConfig()->getConfig()['secretKey'];

        if (strpos($secretKey, 'test') !== false) {
            $this->context->buildViolation($constraint->noTestKeyMessage)->addViolation();
        }
    }
}
