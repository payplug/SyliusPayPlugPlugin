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
    public function __construct(
        private RepositoryInterface $gatewayConfigRepository,
        private RepositoryInterface $paymentMethodRepository,
        private ChannelContextInterface $channelContext,
        private OneyChecker $oneyChecker,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_oney_enabled', $this->isOneyEnabled(...)),
        ];
    }

    public function isOneyEnabled(): bool
    {
        /** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfig|null $gateway */
        $gateway = $this->gatewayConfigRepository->findOneBy(['factoryName' => OneyGatewayFactory::FACTORY_NAME]);
        if (null === $gateway) {
            return false;
        }

        /** @var PaymentMethod|null $paymentMethod */
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
