<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

class OneySupportedPaymentChoiceProvider
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private ChannelContextInterface $channelContext,
    ) {
    }

    public function getSupportedPaymentChoices(bool $useOneyPrefix = false): array
    {
        try {
            $config = $this->getPaymentGatewayConfig();

            $values = OneyGatewayFactory::PAYMENT_CHOICES_FEES_FOR[$config['fees_for'] ?? OneyGatewayFactory::CLIENT_FEES];

            if (!$useOneyPrefix) {
                return $values;
            }

            return array_map(fn ($data): string => 'oney_' . $data, $values);
        } catch (\Exception) {
            return [];
        }
    }

    public function getFeesFor(): string
    {
        return $this->getPaymentGatewayConfig()['fees_for'] ?? '';
    }

    /**
     * Scoped to the current channel: `fees_for` drives which instalment choices are offered to the
     * shopper, and since PRE-3628 each channel may run its own Oney gateway config with its own
     * value. A factory-name-only lookup would show one channel's instalment plans on another's.
     */
    private function getPaymentGatewayConfig(): array
    {
        $paymentMethod = $this->resolveOneyPaymentMethod();

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return [];
        }

        /** @var GatewayConfigInterface $gateway */
        $gateway = $paymentMethod->getGatewayConfig();

        return $gateway->getConfig();
    }

    /**
     * `getFeesFor()` is called outside the try/catch in `getSupportedPaymentChoices()`, so this
     * absorbs the no-channel case rather than letting it escape — there being no current channel is
     * an ordinary state (CLI, an admin request), not an error.
     */
    private function resolveOneyPaymentMethod(): ?PaymentMethodInterface
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }

        if (!$channel instanceof ChannelInterface) {
            return null;
        }

        return $this->paymentMethodRepository->findOneEnabledByGatewayNameAndChannel(
            OneyGatewayFactory::FACTORY_NAME,
            $channel,
        );
    }
}
