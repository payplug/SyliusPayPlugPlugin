<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

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

        if (!$value instanceof PaymentMethodInterface) {
            return;
        }
        $paymentMethod = $value;

        try {
            $client = $this->apiClientFactory->createForPaymentMethod($paymentMethod);
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
