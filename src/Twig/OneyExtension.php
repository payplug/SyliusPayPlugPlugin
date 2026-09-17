<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Twig;

use PayPlug\SyliusPayPlugPlugin\Checker\OneyChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OneyExtension extends AbstractExtension
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
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

    /**
     * Resolved in one channel-scoped query rather than "find any Oney gateway config, then check
     * it happens to serve this channel". Since PRE-3628 a merchant may run one Oney config per
     * channel; the old shape picked an arbitrary one and then usually rejected it, hiding Oney on
     * every channel but whichever the database returned first.
     */
    public function isOneyEnabled(): bool
    {
        $channel = $this->channelContext->getChannel();

        if (!$channel instanceof ChannelInterface) {
            return false;
        }

        $paymentMethod = $this->paymentMethodRepository->findOneEnabledByGatewayNameAndChannel(
            OneyGatewayFactory::FACTORY_NAME,
            $channel,
        );

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return false;
        }

        return $this->oneyChecker->isEnabled();
    }
}
