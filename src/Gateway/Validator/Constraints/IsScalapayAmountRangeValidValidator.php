<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints;

use Payplug\Exception\PayplugException;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Exception\GatewayConfigurationException;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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
                ->setParameter('%min_amount%', self::formatAmount($authorizedRange['min_amount']))
                ->setParameter('%max_amount%', self::formatAmount($authorizedRange['max_amount']))
                ->addViolation()
            ;
        }
    }

    /**
     * The bounds are EUR cents (the form field is hardcoded to EUR), rendered with two decimals so
     * 500 reads as "5.00" rather than "5".
     */
    private static function formatAmount(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
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

        [$minAmount, $maxAmount] = $this->readConfiguredAmounts($gatewayConfig->getConfig());

        return null === $minAmount && null === $maxAmount ? null : [$minAmount, $maxAmount];
    }

    /**
     * The admin form only ever writes null or an int, but the gateway config is a plain serialized
     * array that a direct DB edit, an import script or an admin API write can leave anything in.
     * PaymentMethodValidator::process() has no surrounding try/catch, so a malformed value
     * degrades to "not configured" — leaving the API bounds in force at checkout — rather than
     * throwing an assertion error that would 500 the admin save.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function readConfiguredAmounts(array $config): array
    {
        $minAmount = $config[ScalapayGatewayFactory::MIN_AMOUNT] ?? null;
        $maxAmount = $config[ScalapayGatewayFactory::MAX_AMOUNT] ?? null;

        try {
            Assert::nullOrInteger($minAmount);
            Assert::nullOrInteger($maxAmount);
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning('Skipping Scalapay amount range validation: the stored range is malformed.', [
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'exception' => $exception->getMessage(),
            ]);

            return [null, null];
        }

        return [$minAmount, $maxAmount];
    }

    /**
     * Fails open: when the authorized range can't be established the config saves unvalidated,
     * matching the plugin's convention of never blocking an admin save on an API hiccup. That is
     * not free — a one-sided range that inverts against the live API bounds slips through and
     * silently hides Scalapay at checkout — so the skip is logged rather than swallowed.
     *
     * @return array{min_amount: int, max_amount: int}|null
     */
    private function resolveAuthorizedRange(PaymentMethodInterface $paymentMethod): ?array
    {
        try {
            $authorizedRange = $this->resolveApiAuthorizedRange($paymentMethod);
        } catch (GatewayConfigurationException | PayplugException | InvalidArgumentException $exception) {
            $this->logger->warning('Skipping Scalapay amount range validation: the PayPlug account could not be read.', [
                'payment_method' => $paymentMethod->getCode(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (null === $authorizedRange) {
            $this->logger->warning('Skipping Scalapay amount range validation: the PayPlug account authorizes no EUR range for Scalapay.', [
                'payment_method' => $paymentMethod->getCode(),
            ]);
        }

        return $authorizedRange;
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
