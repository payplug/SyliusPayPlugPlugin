<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Webmozart\Assert\Assert;

final class IsOneyEnabledValidator extends ConstraintValidator
{
    public function __construct(private PayPlugApiClientFactory $apiClientFactory)
    {
    }

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsOneyEnabled) {
            throw new UnexpectedTypeException($constraint, IsOneyEnabled::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!$value instanceof PaymentMethodInterface) {
            return;
        }

        $paymentMethod = $value;
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return;
        }

        $factoryName = $gatewayConfig->getFactoryName();
        Assert::stringNotEmpty($factoryName);

        if ($factoryName !== OneyGatewayFactory::FACTORY_NAME) {
            return;
        }

        try {
            $checker = new OneyChecker($this->apiClientFactory->createForPaymentMethod($paymentMethod));
            if (false === $checker->isEnabled()) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();
            }
        } catch (UnauthorizedException) {
            // do nothing, this should be handle by IsPayPlugSecretKeyValid Constraint
            return;
        }
    }
}
