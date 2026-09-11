<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Checker;

use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * A channel may be linked to at most one *enabled* gateway config per factory type.
 *
 * Two gateways of the same factory (e.g. "CB 1" and "CB 2") may coexist and both be enabled as
 * long as their channel sets are disjoint; different factory types never conflict with each other.
 * Channels are matched on their code rather than on object identity, so the comparison holds
 * regardless of which identity map each side was loaded through.
 */
final class GatewayChannelConflictChecker
{
    public function __construct(private PaymentMethodRepositoryInterface $paymentMethodRepository)
    {
    }

    /**
     * @return list<array{channel: ChannelInterface, paymentMethod: PaymentMethodInterface}>
     */
    public function findConflicts(PaymentMethodInterface $paymentMethod, string $factoryName): array
    {
        $channelCodes = $this->channelCodes($paymentMethod);

        if (!$paymentMethod->isEnabled() || [] === $channelCodes) {
            return [];
        }

        $conflicts = [];
        foreach ($this->paymentMethodRepository->findEnabledByGatewayName($factoryName) as $rival) {
            if ($this->isSamePaymentMethod($paymentMethod, $rival) || !$rival->isEnabled()) {
                continue;
            }

            foreach ($rival->getChannels() as $rivalChannel) {
                if (
                    $rivalChannel instanceof ChannelInterface &&
                    \in_array($rivalChannel->getCode(), $channelCodes, true)
                ) {
                    $conflicts[] = ['channel' => $rivalChannel, 'paymentMethod' => $rival];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    private function channelCodes(PaymentMethodInterface $paymentMethod): array
    {
        $channelCodes = [];
        foreach ($paymentMethod->getChannels() as $channel) {
            $channelCode = $channel->getCode();
            if (null !== $channelCode) {
                $channelCodes[] = $channelCode;
            }
        }

        return $channelCodes;
    }

    /**
     * On creation the subject has no id yet, so it can never match a persisted rival.
     */
    private function isSamePaymentMethod(PaymentMethodInterface $subject, PaymentMethodInterface $rival): bool
    {
        return null !== $subject->getId() && $subject->getId() === $rival->getId();
    }
}
