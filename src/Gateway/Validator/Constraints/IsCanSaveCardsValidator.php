<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\PermissionCanSaveCardsChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @Annotation
 *
 * @deprecated Use PayplugPermission constraint instead
 */
final class IsCanSaveCardsValidator extends ConstraintValidator
{
    public function __construct(private PayPlugApiClientFactory $apiClientFactory)
    {
    }

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsCanSaveCards) {
            throw new UnexpectedTypeException($constraint, IsCanSaveCards::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_bool($value)) {
            throw new UnexpectedValueException($value, 'boolean');
        }

        $secretKey = $this->context->getRoot()->getData()->getGatewayConfig()->getConfig()['secretKey'];

        try {
            if ($value) {
                $checker = new PermissionCanSaveCardsChecker($this->apiClientFactory->create(PayPlugGatewayFactory::FACTORY_NAME, $secretKey));
                if (false === $checker->isEnabled()) {
                    $this->context->buildViolation($constraint->message)->addViolation();
                }
            }

            return;
        } catch (UnauthorizedException | \LogicException) {
            return;
        }
    }
}
