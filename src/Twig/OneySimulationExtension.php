<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use Payplug\Exception\BadRequestException;
use Payplug\OneySimulation;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneySimulationExtension extends AbstractExtension
{
    /** @var \Sylius\Component\Order\Context\CartContextInterface */
    private $cartContext;

    /** @var \PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface */
    private $oneyClient;

    /** @var \Symfony\Contracts\Cache\CacheInterface */
    private $cache;

    /** @var \Psr\Log\LoggerInterface */
    private $payplugLogger;

    public function __construct(
        PayPlugApiClientInterface $oneyClient,
        CartContextInterface $cartContext,
        CacheInterface $cache,
        LoggerInterface $payplugLogger
    ) {
        $this->cartContext = $cartContext;
        $this->oneyClient = $oneyClient;
        $this->cache = $cache;
        $this->payplugLogger = $payplugLogger;
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
        $country = \explode('_', $currentCart->getLocaleCode() ?? 'fr_FR')[1];
        $cacheKey = \sprintf('oney_simulation_%s_%s', $country, $currentCart->getTotal());

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($currentCart, $country): array {
            $item->expiresAfter(new \DateInterval('P1D')); // One day of cache
            $data = [
                'amount' => $currentCart->getTotal(),
                'country' => $country,
                'operations' => [
                    'x3_with_fees',
                    'x4_with_fees',
                ],
            ];
            $this->payplugLogger->debug('[PayPlug] Call oney simulation with following data', $data);

            try {
                $currency = $currentCart->getCurrencyCode();
                $accountData = $this->oneyClient->getAccount();
                $simulationData = OneySimulation::getSimulations($data, $this->oneyClient->getConfiguration());

                $this->payplugLogger->debug('[PayPlug] Oney simulation response', $simulationData);

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
        });
    }
}
