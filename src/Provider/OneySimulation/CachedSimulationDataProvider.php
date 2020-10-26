<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Provider\OneySimulation;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CachedSimulationDataProvider implements OneySimulationDataProviderInterface
{
    /**
     * @var OneySimulationDataProviderInterface
     */
    private $decorated;
    /**
     * @var \Symfony\Contracts\Cache\CacheInterface
     */
    private $cache;

    public function __construct(OneySimulationDataProviderInterface $decorated, CacheInterface $cache)
    {
        $this->decorated = $decorated;
        $this->cache = $cache;
    }

    public function getForCart(OrderInterface $cart): array
    {
        $country = \explode('_', $cart->getLocaleCode() ?? 'fr_FR')[1];
        $cacheKey = \sprintf('oney_simulation_%s_%s', $country, $cart->getTotal());

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($cart): array {
            $item->expiresAfter(new \DateInterval('P1D')); // One day of cache
            return $this->decorated->getForCart($cart);
        });
    }
}
