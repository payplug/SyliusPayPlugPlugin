<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\UnauthorizedException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\CanSavePayplugPaymentMethodChecker;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\UhfGatewayFactory;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Webmozart\Assert\Assert;

/**
 * @Annotation
 */
final class IsCanSavePaymentMethodValidator extends ConstraintValidator
{
    // Unified Hosted Fields processes card payments directly (like `payplug`), it is not an
    // alternative payment method requiring its own per-account enablement flag from PayPlug.
    private const GATEWAYS_SKIP = [PayPlugGatewayFactory::FACTORY_NAME, OneyGatewayFactory::FACTORY_NAME, UhfGatewayFactory::FACTORY_NAME];

    public function __construct(private PayPlugApiClientFactoryInterface $apiClientFactory)
    {
    }

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsCanSavePaymentMethod) {
            throw new UnexpectedTypeException($constraint, IsCanSavePaymentMethod::class);
        }

        if (!$value instanceof PaymentMethodInterface) {
            return;
        }

        if ($value->isEnabled() === false) {
            return;
        }

        $factoryName = $value->getGatewayConfig()?->getFactoryName();
        $channels = $value->getChannels();

        Assert::stringNotEmpty($factoryName);

        try {
            $checker = new CanSavePayplugPaymentMethodChecker($this->apiClientFactory->createForPaymentMethod($value));

            if (in_array($factoryName, self::GATEWAYS_SKIP, true)) {
                return;
            }

            if (!$checker->isEnabled($factoryName, $channels)) {
                $this->context->buildViolation(sprintf($constraint->noAccessMessage, $factoryName))->addViolation();

                return;
            }

            if (!$checker->isLive()) {
                $this->context->buildViolation(sprintf($constraint->noTestKeyMessage, $factoryName))->addViolation();

                return;
            }
        } catch (GatewayConfigurationException | UnauthorizedException) {
            $this->context->buildViolation(sprintf($constraint->noAccessMessage, $factoryName))->addViolation();
        } catch (\LogicException) {
            return;
        }
    }
}
