<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use Payplug\Exception\BadRequestException;
use Payplug\OneySimulation;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class OneySimulationDataProvider implements OneySimulationDataProviderInterface
{
    /** @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface */
    private $oneyClient;

    /** @var \Psr\Log\LoggerInterface */
    private $payplugLogger;

    public function __construct(
        PayPlugApiClientInterface $oneyClient,
        LoggerInterface $payplugLogger
    ) {
        $this->oneyClient = $oneyClient;
        $this->payplugLogger = $payplugLogger;
    }

    public function getForCart(OrderInterface $cart): array
    {
        $country = \explode('_', $cart->getLocaleCode() ?? 'fr_FR')[1];

        $data = [
            'amount' => $cart->getTotal(),
            'country' => $country,
            'operations' => [
                'x3_with_fees',
                'x4_with_fees',
            ],
        ];
        $this->payplugLogger->debug('[PayPlug] Call oney simulation with following data', $data);

        try {
            $currency = $cart->getCurrencyCode();
            $accountData = $this->oneyClient->getAccount();
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
        } catch (BadRequestException $exception) {
            return [];
        }
    }
}
