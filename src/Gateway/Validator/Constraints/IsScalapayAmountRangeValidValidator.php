<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\PayplugException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException;

final class IsScalapayAmountRangeValidValidator extends ConstraintValidator
{
    public function __construct(
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private AccountAmountRangeResolver $amountRangeResolver,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof IsScalapayAmountRangeValid) {
            throw new UnexpectedTypeException($constraint, IsScalapayAmountRangeValid::class);
        }

        if (!$value instanceof PaymentMethodInterface) {
            return;
        }

        $configuredAmounts = $this->resolveApplicableConfiguredAmounts($value, $constraint);
        if (null === $configuredAmounts) {
            return;
        }

        $authorizedRange = $this->resolveAuthorizedRange($value);
        if (null === $authorizedRange) {
            return;
        }

        $this->applyRangeViolations($configuredAmounts, $authorizedRange, $constraint);
    }

    /**
     * Resolves the merchant-configured amounts, applying the early guards that don't need a
     * live API call: the method must be enabled, amounts must be configured, and — when both
     * sides are explicitly set — locally consistent.
     *
     * @return array{0: int|null, 1: int|null}|null
     */
    private function resolveApplicableConfiguredAmounts(
        PaymentMethodInterface $paymentMethod,
        IsScalapayAmountRangeValid $constraint,
    ): ?array {
        $configuredAmounts = false !== $paymentMethod->isEnabled() ? $this->resolveConfiguredAmounts($paymentMethod) : null;
        if (null === $configuredAmounts) {
            return null;
        }

        [$minAmount, $maxAmount] = $configuredAmounts;

        if (\is_int($minAmount) && \is_int($maxAmount) && $minAmount > $maxAmount) {
            $this->context->buildViolation($constraint->minGreaterThanMaxMessage)->addViolation();

            return null;
        }

        return $configuredAmounts;
    }

    /**
     * @param array{0: int|null, 1: int|null}   $configuredAmounts
     * @param array{min_amount: int, max_amount: int} $authorizedRange
     */
    private function applyRangeViolations(
        array $configuredAmounts,
        array $authorizedRange,
        IsScalapayAmountRangeValid $constraint,
    ): void {
        [$minAmount, $maxAmount] = $configuredAmounts;

        // A merchant may configure only one side of the range; the other falls back to the
        // API bound at checkout (see SupportedMethodsProvider), so the min>max check must
        // compare against that same effective range, not just the explicitly configured side.
        $effectiveMinAmount = $minAmount ?? $authorizedRange['min_amount'];
        $effectiveMaxAmount = $maxAmount ?? $authorizedRange['max_amount'];

        if ($effectiveMinAmount > $effectiveMaxAmount) {
            $this->context->buildViolation($constraint->minGreaterThanMaxMessage)->addViolation();

            return;
        }

        if (
            (\is_int($minAmount) && $minAmount < $authorizedRange['min_amount']) ||
            (\is_int($maxAmount) && $maxAmount > $authorizedRange['max_amount'])
        ) {
            $this->context->buildViolation($constraint->outOfRangeMessage)
                ->setParameter('%min_amount%', (string) ($authorizedRange['min_amount'] / 100))
                ->setParameter('%max_amount%', (string) ($authorizedRange['max_amount'] / 100))
                ->addViolation()
            ;
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}|null
     */
    private function resolveConfiguredAmounts(PaymentMethodInterface $paymentMethod): ?array
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (!$gatewayConfig instanceof GatewayConfigInterface || ScalapayGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            return null;
        }

        $config = $gatewayConfig->getConfig();
        $minAmount = $config[ScalapayGatewayFactory::MIN_AMOUNT] ?? null;
        $maxAmount = $config[ScalapayGatewayFactory::MAX_AMOUNT] ?? null;
        Assert::nullOrInteger($minAmount);
        Assert::nullOrInteger($maxAmount);

        if (null === $minAmount && null === $maxAmount) {
            return null;
        }

        return [$minAmount, $maxAmount];
    }

    /**
     * @return array{min_amount: int, max_amount: int}|null
     */
    private function resolveAuthorizedRange(PaymentMethodInterface $paymentMethod): ?array
    {
        try {
            return $this->resolveApiAuthorizedRange($paymentMethod);
        } catch (GatewayConfigurationException | PayplugException | InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{min_amount: int, max_amount: int}|null
     */
    private function resolveApiAuthorizedRange(PaymentMethodInterface $paymentMethod): ?array
    {
        $account = $this->apiClientFactory->createForPaymentMethod($paymentMethod)->getAccount();
        $currencies = $this->amountRangeResolver->resolve($account, ScalapayGatewayFactory::PAYMENT_METHOD_SCALAPAY);

        return $currencies['EUR'] ?? null;
    }
}
