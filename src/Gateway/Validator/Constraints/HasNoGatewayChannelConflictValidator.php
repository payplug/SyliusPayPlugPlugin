<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class HasNoGatewayChannelConflictValidator extends ConstraintValidator
{
    public function __construct(private GatewayChannelConflictChecker $conflictChecker)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof HasNoGatewayChannelConflict) {
            throw new UnexpectedTypeException($constraint, HasNoGatewayChannelConflict::class);
        }

        if (!$value instanceof PaymentMethodInterface) {
            return;
        }

        $factoryName = $this->resolveFactoryName($value);

        if (null === $factoryName) {
            return;
        }

        foreach ($this->conflictChecker->findConflicts($value, $factoryName) as $conflict) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%channel%', (string) $conflict['channel']->getCode())
                ->setParameter('%payment_method%', (string) $conflict['paymentMethod']->getName())
                ->atPath('channels')
                ->addViolation();
        }
    }

    /**
     * Null for anything this constraint has no opinion on — a method with no gateway config, or a
     * disabled one. `findConflicts()` bails on a disabled subject anyway; short-circuiting here
     * saves the query.
     */
    private function resolveFactoryName(PaymentMethodInterface $paymentMethod): ?string
    {
        if (!$paymentMethod->isEnabled()) {
            return null;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return null;
        }

        return $gatewayConfig->getFactoryName();
    }
}
