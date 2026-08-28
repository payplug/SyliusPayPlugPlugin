<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\Behat\Mocker;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class StubOneySimulationDataProvider implements OneySimulationDataProviderInterface
{
    public function getForCart(OrderInterface $cart): array
    {
        return [
            'current_total' => 45845,
            'min_amount' => 10000,
            'max_amount' => 300000,
            'x3_with_fees' => [
                'installments' => [
                    [
                        'date' => '2020-11-26T01:00:00.000000Z',
                        'amount' => 15281,
                    ],
                    [
                        'date' => '2020-12-26T01:00:00.000000Z',
                        'amount' => 15283,
                    ],
                ],
                'total_cost' => 458,
                'nominal_annual_percentage_rate' => 15.0,
                'effective_annual_percentage_rate' => 20.0,
                'down_payment_amount' => 15739,
            ],
            'x4_with_fees' => [
                'installments' => [
                    [
                        'date' => '2020-11-26T01:00:00.000000Z',
                        'amount' => 11461,
                    ],
                    [
                        'date' => '2020-12-26T01:00:00.000000Z',
                        'amount' => 11461,
                    ],
                    [
                        'date' => '2021-01-26T01:00:00.000000Z',
                        'amount' => 11462,
                    ],
                ],
                'total_cost' => 458,
                'nominal_annual_percentage_rate' => 15.0,
                'effective_annual_percentage_rate' => 20.0,
                'down_payment_amount' => 11919,
            ],
        ];
    }
}
