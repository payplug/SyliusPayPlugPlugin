<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use Payplug\OneySimulation;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Provider\OneySupportedPaymentChoiceProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OneySimulationDataProvider implements OneySimulationDataProviderInterface
{
    public function __construct(
        #[Autowire('@payplug_sylius_payplug_plugin.api_client.oney')]
        private PayPlugApiClientInterface $oneyClient,
        #[Autowire('@monolog.logger.payum')]
        private LoggerInterface $logger,
        private OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider,
    ) {
    }

    public function getForCart(OrderInterface $cart): array
    {
        $country = \explode('_', $cart->getLocaleCode() ?? 'fr_FR')[1];

        $data = [
            'amount' => $cart->getTotal(),
            'country' => $country,
            'operations' => $this->oneySupportedPaymentChoiceProvider->getSupportedPaymentChoices(),
        ];
        $this->logger->debug('[PayPlug] Call oney simulation with following data', $data);

        try {
            $currency = $cart->getCurrencyCode();
            $accountData = $this->oneyClient->getAccount(true);
            $simulationData = OneySimulation::getSimulations($data, $this->oneyClient->getConfiguration());

            $this->logger->debug('[PayPlug] Oney simulation response', $simulationData);

            return \array_merge(
                [
                    'current_total' => $cart->getTotal(),
                    'min_amount' => $accountData['configuration']['oney']['min_amounts'][$currency],
                    'max_amount' => $accountData['configuration']['oney']['max_amounts'][$currency],
                ],
                $simulationData,
            );
        } catch (\Exception) {
            return [];
        }
    }
}
