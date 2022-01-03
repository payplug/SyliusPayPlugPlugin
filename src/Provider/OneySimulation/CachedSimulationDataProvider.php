<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use PayPlug\SyliusPayPlugPlugin\Provider\OneySupportedPaymentChoiceProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CachedSimulationDataProvider implements OneySimulationDataProviderInterface
{
    private OneySimulationDataProviderInterface $decorated;

    private CacheInterface $cache;

    private OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider;

    public function __construct(
        OneySimulationDataProviderInterface $decorated,
        CacheInterface $cache,
        OneySupportedPaymentChoiceProvider $oneySupportedPaymentChoiceProvider
    ) {
        $this->decorated = $decorated;
        $this->cache = $cache;
        $this->oneySupportedPaymentChoiceProvider = $oneySupportedPaymentChoiceProvider;
    }

    public function getForCart(OrderInterface $cart): array
    {
        $country = \explode('_', $cart->getLocaleCode() ?? 'fr_FR')[1];
        $cacheKey = \sprintf(
            'oney_simulation_%s_%s_%s',
            $country,
            $cart->getTotal(),
            $this->oneySupportedPaymentChoiceProvider->getFeesFor(),
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($cart): array {
            $item->expiresAfter(new \DateInterval('P1D')); // One day of cache

            return $this->decorated->getForCart($cart);
        });
    }
}
