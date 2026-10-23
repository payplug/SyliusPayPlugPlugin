<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Gateway\ScalapayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class PproConfigurationRefresher implements PproConfigurationRefresherInterface
{
    private const CURRENCY = 'EUR';

    /** How recent stored limits must be for refreshIfStale() to keep them without asking PayPlug. */
    private const MIN_REFRESH_INTERVAL = '-5 minutes';

    /** Where the Scalapay-only form stored the merchant range, in cents, before the `payment_method` block. */
    private const LEGACY_SCALAPAY_MIN_AMOUNT = 'min_amount';

    private const LEGACY_SCALAPAY_MAX_AMOUNT = 'max_amount';

    public function __construct(
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private AccountAmountRangeResolver $amountRangeResolver,
        private AccountAllowedCountriesResolver $allowedCountriesResolver,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(PaymentMethodInterface $paymentMethod, bool $flush = true): PproRefreshResult
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        $apiKey = PproPaymentMethods::apiKey($gatewayConfig?->getFactoryName());

        if (null === $gatewayConfig || null === $apiKey) {
            return PproRefreshResult::notRefreshed();
        }

        try {
            $apiLimits = $this->fetchApiLimits($paymentMethod, $apiKey, $gatewayConfig->getConfig());

            // Read back after the PayPlug call, which can take a while: a save of the gateway config
            // made meanwhile (credentials, merchant settings) is kept, and only this block is replaced.
            $this->entityManager->refresh($gatewayConfig);
            $config = $gatewayConfig->getConfig();
            $previous = $this->readPrevious($gatewayConfig, $config);
            $refreshed = PproConfig::fromApiLimits($apiLimits, $previous);

            $config[PproConfig::CONFIG_KEY] = $refreshed->toArray();
            $gatewayConfig->setConfig($config);

            // A failed flush closes the entity manager, so there is nothing to restore in memory.
            if ($flush) {
                $this->entityManager->flush();
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('[PayPlug] Could not refresh the PPRO limits from the PayPlug account.', [
                'factory_name' => $gatewayConfig->getFactoryName(),
                'exception' => $exception->getMessage(),
            ]);

            return PproRefreshResult::notRefreshed();
        }

        $this->warnOnEmptyAmountRange($gatewayConfig, $refreshed);

        return new PproRefreshResult(true, $refreshed->adjustsSettingsOf($previous));
    }

    public function refreshIfStale(PaymentMethodInterface $paymentMethod, bool $flush = true): PproRefreshResult
    {
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        $stored = PproConfig::fromArray($config[PproConfig::CONFIG_KEY] ?? null);

        $isFresh = null !== $stored &&
            $stored->live === (true === ($config['live'] ?? false)) &&
            $stored->isRefreshedSince($this->clock->now()->modify(self::MIN_REFRESH_INTERVAL));

        return $isFresh ? PproRefreshResult::upToDate() : $this->refresh($paymentMethod, $flush);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function fetchApiLimits(PaymentMethodInterface $paymentMethod, string $apiKey, array $config): PproConfig
    {
        $account = $this->apiClientFactory->createForPaymentMethod($paymentMethod)->getAccount(true);

        $paymentMethods = $account['payment_methods'] ?? null;
        $entry = \is_array($paymentMethods) ? ($paymentMethods[$apiKey] ?? null) : null;
        if (!\is_array($entry)) {
            throw new \UnexpectedValueException(sprintf('The PayPlug account has no "%s" payment method entry.', $apiKey));
        }

        $enabled = $entry['enabled'] ?? true;
        if (!\is_bool($enabled)) {
            throw new \UnexpectedValueException('The "enabled" flag is not a boolean.');
        }

        $bounds = $this->amountRangeResolver->resolve($account, $apiKey)[self::CURRENCY] ?? null;

        return new PproConfig(
            $apiKey,
            $enabled,
            $this->allowedCountriesResolver->resolve($account, $apiKey),
            $bounds['min_amount'] ?? null,
            $bounds['max_amount'] ?? null,
            null,
            null,
            null,
            true === ($config['live'] ?? false),
            $this->clock->now()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * The merchant settings to keep. Before any `payment_method` block exists, a Scalapay gateway
     * still holds the range its former Scalapay-only form saved: it seeds the merchant range once.
     *
     * @param array<array-key, mixed> $config
     */
    private function readPrevious(GatewayConfigInterface $gatewayConfig, array $config): ?PproConfig
    {
        $stored = $config[PproConfig::CONFIG_KEY] ?? null;
        if (null !== $stored || ScalapayGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            return PproConfig::fromArray($stored);
        }

        $min = self::readLegacyAmount($config[self::LEGACY_SCALAPAY_MIN_AMOUNT] ?? null);
        $max = self::readLegacyAmount($config[self::LEGACY_SCALAPAY_MAX_AMOUNT] ?? null);

        return null === $min && null === $max ? null : new PproConfig(null, true, null, null, null, $min, $max, null);
    }

    private static function readLegacyAmount(mixed $amount): ?int
    {
        return \is_int($amount) && $amount >= 0 ? $amount : null;
    }

    /**
     * Logged here, once per refresh, rather than on every checkout render that hides the method.
     */
    private function warnOnEmptyAmountRange(GatewayConfigInterface $gatewayConfig, PproConfig $refreshed): void
    {
        if (!$refreshed->hasEmptyAmountRange()) {
            return;
        }

        $this->logger->warning('[PayPlug] The PPRO amount range is empty; the payment method is hidden until its limits change.', [
            'factory_name' => $gatewayConfig->getFactoryName(),
            'min' => $refreshed->effectiveMin(),
            'max' => $refreshed->effectiveMax(),
        ]);
    }
}
