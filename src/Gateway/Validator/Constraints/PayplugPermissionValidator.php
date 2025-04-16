<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PayplugPermissionValidator extends ConstraintValidator
{
    public function __construct(private PayPlugApiClientFactory $apiClientFactory)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PayplugPermission) {
            throw new UnexpectedTypeException($constraint, PayplugPermission::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_bool($value)) {
            throw new UnexpectedValueException($value, 'boolean');
        }

        if (false === $value) {
            return;
        }

        $secretKey = $this->context->getRoot()->getData()->getGatewayConfig()->getConfig()['secretKey'];

        try {
            $client = $this->apiClientFactory->create(PayPlugGatewayFactory::FACTORY_NAME, $secretKey);
            $accountPermissions = $client->getPermissions();

            if (false === $accountPermissions[$constraint->permission]) {
                $this->context
                    ->buildViolation($constraint->message)
                    ->addViolation();
            }

            return;
        } catch (UnauthorizedException | \LogicException) {
            return;
        }
    }
}
