<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use Payplug\OneySimulation;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Provider\OneySupportedPaymentChoiceProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class OneySimulationDataProvider implements OneySimulationDataProviderInterface
{
    private PayPlugApiClientInterface $oneyClient;
    private LoggerInterface $payplugLogger;

    private OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider;

    public function __construct(
        PayPlugApiClientInterface $oneyClient,
        LoggerInterface $payplugLogger,
        OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider
    ) {
        $this->oneyClient = $oneyClient;
        $this->payplugLogger = $payplugLogger;
        $this->oneySupportedPaymentChoiceProvider = $oneySupportedPaymentChoiceProvider;
    }

    public function getForCart(OrderInterface $cart): array
    {
        $country = strtoupper(substr($cart->getLocaleCode() ?? 'fr_FR', -2));

        $data = [
            'amount' => $cart->getTotal(),
            'country' => $country,
            'operations' => $this->oneySupportedPaymentChoiceProvider->getSupportedPaymentChoices(),
        ];
        $this->payplugLogger->debug('[PayPlug] Call oney simulation with following data', $data);

        try {
            $currency = $cart->getCurrencyCode();
            $accountData = $this->oneyClient->getAccount(true);
            $simulationData = OneySimulation::getSimulations($data, $this->oneyClient->getConfiguration());

            $this->payplugLogger->debug('[PayPlug] Oney simulation response', $simulationData);

            return \array_merge(
                [
                    'current_total' => $cart->getTotal(),
                    'min_amount' => $accountData['configuration']['oney']['min_amounts'][$currency],
                    'max_amount' => $accountData['configuration']['oney']['max_amounts'][$currency],
                ],
                $simulationData
            );
        } catch (\Exception $exception) {
            return [];
        }
    }
}
