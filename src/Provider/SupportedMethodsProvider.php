<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider;

use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Webmozart\Assert\Assert;

final class SupportedMethodsProvider
{
    public function __construct(private CurrencyContextInterface $currencyContext)
    {
    }

    public function provide(array $supportedMethods, string $factoryName, array $authorizedCurrencies, int $paymentAmount): array
    {
        $activeCurrencyCode = $this->currencyContext->getCurrencyCode();

        foreach ($supportedMethods as $key => $paymentMethod) {
            Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);

            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();

            if ($factoryName !== $gatewayConfig->getFactoryName()) {
                continue;
            }

            if (!\array_key_exists($activeCurrencyCode, $authorizedCurrencies)) {
                unset($supportedMethods[$key]);

                continue;
            }

            if ($paymentAmount < $authorizedCurrencies[$activeCurrencyCode]['min_amount']
                || $paymentAmount > $authorizedCurrencies[$activeCurrencyCode]['max_amount']
            ) {
                unset($supportedMethods[$key]);

                continue;
            }
        }

        return $supportedMethods;
    }
}
