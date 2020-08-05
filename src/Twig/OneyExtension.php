<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneyExtension extends AbstractExtension
{
    /**
     * @var \Sylius\Component\Resource\Repository\RepositoryInterface
     */
    private $gatewayConfigRepository;
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker
     */
    private $oneyChecker;
    /**
     * @var \Sylius\Component\Resource\Repository\RepositoryInterface
     */
    private $paymentMethodRepository;

    public function __construct(
        RepositoryInterface $gatewayConfigRepository,
        RepositoryInterface $paymentMethodRepository,
        OneyChecker $oneyChecker
    ) {
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->oneyChecker = $oneyChecker;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, you should add a third
            // parameter: ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/2.x/advanced.html#automatic-escaping
            // new TwigFilter('filter_name', [$this, 'doSomething']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_oney_enabled', [$this, 'isOneyEnabled']),
        ];
    }

    public function isOneyEnabled(): bool
    {
        // TODO : add cache on this response

        /** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfig $gateway */
        $gateway = $this->gatewayConfigRepository->findOneBy(['factoryName' => OneyGatewayFactory::FACTORY_NAME]);
        if (null === $gateway) {
            return false;
        }

        /** @var \Sylius\Component\Payment\Model\PaymentMethod $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['gatewayConfig' => $gateway]);
        if (null === $paymentMethod || false === $paymentMethod->isEnabled()) {
            return false;
        }

        return $this->oneyChecker->isEnabled();
    }
}
