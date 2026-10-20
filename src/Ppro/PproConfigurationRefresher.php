<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Ppro;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAllowedCountriesResolver;
use PayPlug\SyliusPayPlugPlugin\Resolver\AccountAmountRangeResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class PproConfigurationRefresher implements PproConfigurationRefresherInterface
{
    private const CURRENCY = 'EUR';

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
            $config = $gatewayConfig->getConfig();
            $previous = PproConfig::fromArray($config[PproConfig::CONFIG_KEY] ?? null);
            $refreshed = PproConfig::fromApiLimits($this->fetchApiLimits($paymentMethod, $apiKey, $config), $previous);

            $config[PproConfig::CONFIG_KEY] = $refreshed->toArray();
            $this->store($gatewayConfig, $config, $flush);
        } catch (\Throwable $exception) {
            $this->logger->warning('[PayPlug] Could not refresh the PPRO limits from the PayPlug account.', [
                'factory_name' => $gatewayConfig->getFactoryName(),
                'exception' => $exception->getMessage(),
            ]);

            return PproRefreshResult::notRefreshed();
        }

        return new PproRefreshResult(true, $refreshed->adjustsSettingsOf($previous));
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
     * @param array<string, mixed> $config
     */
    private function store(GatewayConfigInterface $gatewayConfig, array $config, bool $flush): void
    {
        $previousConfig = $gatewayConfig->getConfig();
        $gatewayConfig->setConfig($config);

        if (!$flush) {
            return;
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $gatewayConfig->setConfig($previousConfig);

            throw $exception;
        }
    }
}
