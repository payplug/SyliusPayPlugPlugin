<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneySimulationExtension extends AbstractExtension
{
    /** @var \Sylius\Component\Order\Context\CartContextInterface */
    private $cartContext;

    /** @var \PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface */
    private $oneySimulationDataProvider;

    /** @var \Symfony\Component\HttpFoundation\RequestStack */
    private $requestStack;

    /** @var \Sylius\Component\Core\Repository\OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        CartContextInterface $cartContext,
        OneySimulationDataProviderInterface $oneySimulationDataProvider,
        RequestStack $requestStack,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->cartContext = $cartContext;
        $this->oneySimulationDataProvider = $oneySimulationDataProvider;
        $this->requestStack = $requestStack;
        $this->orderRepository = $orderRepository;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_simulation_data', [$this, 'getSimulationData']),
        ];
    }

    public function getSimulationData(): array
    {
        return $this->oneySimulationDataProvider->getForCart($this->getCartOrOrder());
    }

    private function getCartOrOrder(): OrderInterface
    {
        $currentRequest = $this->requestStack->getCurrentRequest();

        if (!$currentRequest instanceof Request || $currentRequest->get('_route') !== 'sylius_shop_order_show') {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();

            return $cart;
        }

        $order = $this->orderRepository->findOneByTokenValue($currentRequest->get('tokenValue'));

        if (!$order instanceof OrderInterface) {
            throw new \Exception('');
        }

        return $order;
    }
}
