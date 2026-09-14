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

        return \array_values(\array_filter(
            $this->claims($paymentMethod, $factoryName),
            static fn (string $channelCode): bool => \in_array($channelCode, $channelCodes, true),
            \ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * The channels the gateway channel picker must render as unselectable, keyed by channel code.
     *
     * Deliberately unconditional where `findConflicts()` returns early on the subject's `enabled`
     * flag: the picker is rendered before the admin has decided anything. Building both answers
     * from the same `claims()` lookup is what keeps the disabled options and the submit-time rule
     * in step.
     *
     * The subject's own channels are excluded, however. A browser does not submit a disabled
     * checkbox, so disabling one that is *checked* would silently drop that channel the next time
     * the admin saves — the very removal this picker exists to prevent. An overlap that already
     * exists in the data therefore stays selectable and is reported by `findConflicts()` on submit
     * instead. `claims()` alone cannot cover this: it excludes the subject by id, which says
     * nothing about a *different* enabled payment method holding a channel the subject also holds.
     *
     * @return array<string, PaymentMethodInterface>
     */
    public function findClaimedChannels(PaymentMethodInterface $paymentMethod, string $factoryName): array
    {
        return \array_diff_key(
            \array_map(
                static fn (array $claim): PaymentMethodInterface => $claim['paymentMethod'],
                $this->claims($paymentMethod, $factoryName),
            ),
            \array_flip($this->channelCodes($paymentMethod)),
        );
    }

    /**
     * Channels held by *other* enabled payment methods of the same factory.
     *
     * Keying by channel code collapses the (post-PRE-3628 unreachable) case of two enabled rivals
     * holding the same channel down to a single claim, so neither caller reports it twice.
     *
     * @return array<string, array{channel: ChannelInterface, paymentMethod: PaymentMethodInterface}>
     */
    private function claims(PaymentMethodInterface $paymentMethod, string $factoryName): array
    {
        $claims = [];
        foreach ($this->paymentMethodRepository->findEnabledByGatewayName($factoryName) as $rival) {
            if ($this->isSamePaymentMethod($paymentMethod, $rival) || !$rival->isEnabled()) {
                continue;
            }

            foreach ($rival->getChannels() as $rivalChannel) {
                if (!$rivalChannel instanceof ChannelInterface) {
                    continue;
                }

                $channelCode = $rivalChannel->getCode();

                if (null !== $channelCode) {
                    $claims[$channelCode] = ['channel' => $rivalChannel, 'paymentMethod' => $rival];
                }
            }
        }

        return $claims;
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
