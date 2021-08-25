<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSaveCardsChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @Annotation
 */
final class IsCanSaveCardsValidator extends ConstraintValidator
{
    /** @var PayPlugApiClientFactory */
    private $apiClientFactory;

    public function __construct(PayPlugApiClientFactory $apiClientFactory)
    {
        $this->apiClientFactory = $apiClientFactory;
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
            if (true === $value) {
                $checker = new CanSaveCardsChecker($this->apiClientFactory->create(PayPlugGatewayFactory::FACTORY_NAME, $secretKey));
                if (false === $checker->isEnabled()) {
                    $this->context->buildViolation($constraint->message)->addViolation();
                }
            }
            return;

        } catch (UnauthorizedException $exception) {
            return;
        }
    }
}
