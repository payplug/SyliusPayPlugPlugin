<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveBancontactMethodChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @Annotation
 */
final class IsCanSaveBancontactMethodValidator extends ConstraintValidator
{
    private PayPlugApiClientFactory $apiClientFactory;

    public function __construct(PayPlugApiClientFactory $apiClientFactory)
    {
        $this->apiClientFactory = $apiClientFactory;
    }

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

        $checker = new CanSaveBancontactMethodChecker($this->apiClientFactory->create(BancontactGatewayFactory::FACTORY_NAME, $secretKey));

        try {
            if (!$checker->isLive()) {
                $this->context->buildViolation($constraint->noTestKeyMessage)->addViolation();

                return;
            }

            if (!$checker->isEnabled()) {
                $this->context->buildViolation($constraint->noAccessMessage)->addViolation();
            }

            return;
        } catch (UnauthorizedException|\LogicException $exception) {
            return;
        }
    }
}
