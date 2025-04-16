<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation\OneySimulationDataProviderInterface;
use PayPlug\SyliusPayPlugPlugin\Provider\OneySupportedPaymentChoiceProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Webmozart\Assert\Assert;

final class OneySimulationExtension extends AbstractExtension
{
    public function __construct(
        private CartContextInterface $cartContext,
        private OneySimulationDataProviderInterface $oneySimulationDataProvider,
        private RequestStack $requestStack,
        private OrderRepositoryInterface $orderRepository,
        private OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oney_simulation_data', [$this, 'getSimulationData']),
            new TwigFunction('oney_supported_choices', [$this, 'getSupportedPaymentChoices']),
            new TwigFunction('is_oney_without_fees', [$this, 'isPaymentChoiceWithoutFees']),
        ];
    }

    public function getSimulationData(): array
    {
        return $this->oneySimulationDataProvider->getForCart($this->getCartOrOrder());
    }

    private function getCartOrOrder(): OrderInterface
    {
        $currentRequest = $this->requestStack->getCurrentRequest();

        if (!$currentRequest instanceof Request || 'sylius_shop_order_show' !== $currentRequest->get('_route')) {
            /** @var OrderInterface $cart */
            $cart = $this->cartContext->getCart();

            return $cart;
        }

        $tokenValue = $currentRequest->get('tokenValue');
        Assert::string($tokenValue);

        $order = $this->orderRepository->findOneByTokenValue($tokenValue);

        if (!$order instanceof OrderInterface) {
            throw new \Exception('No order found.');
        }

        return $order;
    }

    public function getSupportedPaymentChoices(): array
    {
        return $this->oneySupportedPaymentChoiceProvider->getSupportedPaymentChoices();
    }

    public function isPaymentChoiceWithoutFees(): bool
    {
        return \count(\array_filter(
            $this->getSupportedPaymentChoices(),
            fn (string $choice) => \in_array($choice, OneyGatewayFactory::ONEY_WITHOUT_FEES_CHOICES, true)
        )) > 0;
    }
}
