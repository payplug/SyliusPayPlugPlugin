<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSavePayplugPaymentMethodChecker;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Webmozart\Assert\Assert;

/**
 * @Annotation
 */
final class IsCanSavePaymentMethodValidator extends ConstraintValidator
{
    private PayPlugApiClientFactory $apiClientFactory;

    public function __construct(PayPlugApiClientFactory $apiClientFactory)
    {
        $this->apiClientFactory = $apiClientFactory;
    }

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsCanSavePaymentMethod) {
            throw new UnexpectedTypeException($constraint, IsCanSavePaymentMethod::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        $factoryName = $this->context->getRoot()->getData()->getGatewayConfig()->getFactoryName();
        $channels = $this->context->getRoot()->getData()->getChannels();

        Assert::string($value);
        Assert::stringNotEmpty($factoryName);

        $checker = new CanSavePayplugPaymentMethodChecker($this->apiClientFactory->create($factoryName, $value));

        try {
            if (!$checker->isLive()) {
                $this->context->buildViolation($constraint->noTestKeyMessage)->addViolation();

                return;
            }

            if (!$checker->isEnabled($factoryName, $channels)) {
                $this->context->buildViolation($constraint->noAccessMessage)->addViolation();
            }

            return;
        } catch (UnauthorizedException|\LogicException $exception) {
            return;
        }
    }
}
