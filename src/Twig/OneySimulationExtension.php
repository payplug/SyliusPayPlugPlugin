<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Payplug\Exception\BadRequestException;
use Payplug\OneySimulation;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Checker\OneyCheckerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneySimulationExtension extends AbstractExtension
{
    /**
     * @var \Sylius\Component\Order\Context\CartContextInterface
     */
    private $cartContext;
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface
     */
    private $oneyClient;

    public function __construct(
        PayPlugApiClientInterface $oneyClient,
        CartContextInterface $cartContext
    ) {
        $this->cartContext = $cartContext;
        $this->oneyClient = $oneyClient;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_simulation_data', [$this, 'getSimulationData']),
        ];
    }

    public function getSimulationData(): array
    {
        /** @var \Sylius\Component\Core\Model\Order $currentCart */
        $currentCart = $this->cartContext->getCart();
        $data = [
            'amount' => $currentCart->getTotal(),
            'country' => 'FR', // TODO retrieve country
            'operations' => [
                'x3_with_fees',
                'x4_with_fees',
            ],
        ];
        try {
            $currency = $currentCart->getCurrencyCode();
            $accountData = $this->oneyClient->getAccount();
            $simulationData =  OneySimulation::getSimulations($data, $this->oneyClient->getConfiguration());

            return \array_merge(
                [
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
