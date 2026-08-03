<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class IsNotCombiningIntegratedPaymentAndHostedFieldsValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsNotCombiningIntegratedPaymentAndHostedFields) {
            throw new UnexpectedTypeException($constraint, IsNotCombiningIntegratedPaymentAndHostedFields::class);
        }

        if (!$value instanceof PaymentMethodInterface) {
            return;
        }

        $gatewayConfig = $value->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return;
        }

        $config = $gatewayConfig->getConfig();
        $integratedPaymentEnabled = true === ($config[PayPlugGatewayFactory::INTEGRATED_PAYMENT] ?? false);
        $hostedFieldsEnabled = true === ($config[PayPlugGatewayFactory::HOSTED_FIELDS] ?? false);

        if ($integratedPaymentEnabled && $hostedFieldsEnabled) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
