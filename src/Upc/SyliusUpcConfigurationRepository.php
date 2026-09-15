<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class SyliusUpcConfigurationRepository implements ScopedConfigurationRepositoryInterface
{
    private ?GatewayConfigInterface $gatewayConfig = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function withGatewayConfig(GatewayConfigInterface $gatewayConfig): ScopedConfigurationRepositoryInterface
    {
        $scoped = clone $this;
        $scoped->gatewayConfig = $gatewayConfig;

        return $scoped;
    }

    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): ScopedConfigurationRepositoryInterface
    {
        return $this->withGatewayConfig(
            $paymentMethod->getGatewayConfig()
                ?? throw new \LogicException('The payment method has no gateway config, so no PayPlug account can be resolved for it.'),
        );
    }

    public function get(string $key): ?string
    {
        $value = $this->findGatewayConfig()->getConfig()[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $gatewayConfig = $this->findGatewayConfig();
        $gatewayConfig->setConfig([...$gatewayConfig->getConfig(), $key => $value]);
        $this->entityManager->flush();
    }

    public function getClientId(): string
    {
        $value = $this->getClientConfig()['client_id'] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function getClientSecret(): string
    {
        $value = $this->getClientConfig()['client_secret'] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function getPublicKeyId(): string
    {
        $value = $this->findGatewayConfig()->getConfig()[PayPlugGatewayFactory::HF_IDENTIFIER] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * Not populated by any current admin field. Returns '' until a future ticket adds a
     * distinct public-key-value config field.
     */
    public function getPublicKeyValue(): string
    {
        return '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getClientConfig(): array
    {
        $config = $this->findGatewayConfig()->getConfig();
        $isLive = true === ($config['live'] ?? false);
        $rawClientConfig = $isLive ? ($config['live_client'] ?? null) : ($config['test_client'] ?? null);

        if (!\is_array($rawClientConfig)) {
            return [];
        }

        return $rawClientConfig;
    }

    /**
     * Deliberately no fallback to findOneBy(['factoryName' => …]). That lookup was correct only
     * while exactly one CB gateway config could exist; since PRE-3628 it returns an arbitrary one
     * of several, which would sign a channel's payment with another channel's account. An
     * unscoped call is a bug at the call site, so it fails loudly here rather than silently
     * resolving the wrong merchant.
     */
    private function findGatewayConfig(): GatewayConfigInterface
    {
        return $this->gatewayConfig ?? throw new \LogicException(
            'The UPC configuration repository has not been scoped to a gateway config. ' .
            'Call withGatewayConfig() with the gateway config of the payment method being handled.',
        );
    }
}
