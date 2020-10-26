<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneySimulationExtension extends AbstractExtension
{
    /** @var \Sylius\Component\Order\Context\CartContextInterface */
    private $cartContext;

    /** @var \PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface */
    private $oneySimulationDataProvider;

    public function __construct(
        CartContextInterface $cartContext,
        OneySimulationDataProviderInterface $oneySimulationDataProvider
    ) {
        $this->cartContext = $cartContext;
        $this->oneySimulationDataProvider = $oneySimulationDataProvider;
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

        return $this->oneySimulationDataProvider->getForCart($currentCart);
    }
}
