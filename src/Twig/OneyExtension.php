<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\PaymentMethod;
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
     * @var \Sylius\Component\Resource\Repository\RepositoryInterface
     */
    private $paymentMethodRepository;
    /**
     * @var \Sylius\Component\Channel\Context\ChannelContextInterface
     */
    private $channelContext;
    /**
     * @var \PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker
     */
    private $oneyChecker;

    public function __construct(
        RepositoryInterface $gatewayConfigRepository,
        RepositoryInterface $paymentMethodRepository,
        ChannelContextInterface $channelContext,
        OneyChecker $oneyChecker
    ) {
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->channelContext = $channelContext;
        $this->oneyChecker = $oneyChecker;
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

        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['gatewayConfig' => $gateway]);
        if (null === $paymentMethod || false === $paymentMethod->isEnabled()) {
            return false;
        }

        $currentChannel = $this->channelContext->getChannel();
        if (!$paymentMethod->getChannels()->contains($currentChannel)) {
            return false;
        }

        return $this->oneyChecker->isEnabled();
    }
}
