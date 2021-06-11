<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class IsOneyEnabledValidator extends ConstraintValidator
{
    /** @var PayPlugApiClientFactory */
    private $apiClientFactory;

    public function __construct(PayPlugApiClientFactory $apiClientFactory)
    {
        $this->apiClientFactory = $apiClientFactory;
    }

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
            $checker = new OneyChecker($this->apiClientFactory->create(OneyGatewayFactory::FACTORY_NAME, $value));

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
