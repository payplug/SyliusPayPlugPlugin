<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class OneySimulationPopin extends AbstractController
{
    /** @var OneySimulationDataProviderInterface */
    private $oneySimulationDataProvider;

    /** @var CartContextInterface */
    private $cartContext;

    public function __construct(
        OneySimulationDataProviderInterface $oneySimulationDataProvider,
        CartContextInterface $cartContext
    ) {
        $this->oneySimulationDataProvider = $oneySimulationDataProvider;
        $this->cartContext = $cartContext;
    }

    public function __invoke(): Response
    {
        /** @var \Sylius\Component\Core\Model\OrderInterface $cart */
        $cart = $this->cartContext->getCart();
        $simulationData = $this->oneySimulationDataProvider->getForCart($cart);

        return $this->render(
            '@PayPlugSyliusPayPlugPlugin/oney/popin.html.twig',
            ['data' => $simulationData]
        );
    }
}
