<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Checker;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Covers the per-channel gateway uniqueness rule: a channel may be linked to at most one
 * *enabled* gateway config per factory type. Two gateways of the same factory may coexist
 * as long as their channel sets are disjoint.
 */
final class GatewayChannelConflictCheckerTest extends TestCase
{
    private PaymentMethodRepositoryInterface&MockObject $paymentMethodRepository;

    private GatewayChannelConflictChecker $checker;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->checker = new GatewayChannelConflictChecker($this->paymentMethodRepository);
    }

    public function testFindConflicts_enabledRivalSharesChannel_isReported(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);
        $rival = $this->paymentMethod(7, true, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository
            ->expects(self::once())
            ->method('findEnabledByGatewayName')
            ->with(PayPlugGatewayFactory::FACTORY_NAME)
            ->willReturn([$rival])
        ;

        $conflicts = $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME);

        self::assertCount(1, $conflicts);
        self::assertSame('WEB_FR', $conflicts[0]['channel']->getCode());
        self::assertSame('CB 1', $conflicts[0]['paymentMethod']->getName());
    }

    public function testFindConflicts_channelSetsAreDisjoint_isAllowed(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);
        $rival = $this->paymentMethod(7, true, ['WEB_IT'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    public function testFindConflicts_twoSharedChannels_bothAreReported(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR', 'WEB_IT', 'WEB_BE']);
        $rival = $this->paymentMethod(7, true, ['WEB_IT', 'WEB_BE'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        $conflicts = $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME);

        self::assertCount(2, $conflicts);
        self::assertSame(
            ['WEB_IT', 'WEB_BE'],
            array_map(static fn (array $conflict): ?string => $conflict['channel']->getCode(), $conflicts),
        );
    }

    public function testFindConflicts_subjectIsDisabled_isAllowedWithoutQuerying(): void
    {
        $subject = $this->paymentMethod(null, false, ['WEB_FR']);

        $this->paymentMethodRepository->expects(self::never())->method('findEnabledByGatewayName');

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    public function testFindConflicts_subjectHasNoChannel_isAllowedWithoutQuerying(): void
    {
        $subject = $this->paymentMethod(null, true, []);

        $this->paymentMethodRepository->expects(self::never())->method('findEnabledByGatewayName');

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    /**
     * Editing an existing gateway must not make it conflict with itself.
     */
    public function testFindConflicts_rivalIsTheSubjectItself_isAllowed(): void
    {
        $subject = $this->paymentMethod(7, true, ['WEB_FR']);
        $itself = $this->paymentMethod(7, true, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$itself]);

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    /**
     * The repository already filters on `enabled`, but the rule is re-asserted here so the whole
     * rule is expressed — and testable — in one place.
     */
    public function testFindConflicts_rivalIsDisabled_isAllowed(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);
        $rival = $this->paymentMethod(7, false, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    public function testFindConflicts_rivalHasNoChannel_isAllowed(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);
        $rival = $this->paymentMethod(7, true, [], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        self::assertSame([], $this->checker->findConflicts($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    /**
     * Different factory types never conflict: the lookup is scoped to the factory being saved.
     */
    public function testFindConflicts_queriesOnlyTheGivenFactory(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);

        $this->paymentMethodRepository
            ->expects(self::once())
            ->method('findEnabledByGatewayName')
            ->with(OneyGatewayFactory::FACTORY_NAME)
            ->willReturn([])
        ;

        self::assertSame([], $this->checker->findConflicts($subject, OneyGatewayFactory::FACTORY_NAME));
    }

    public function testFindClaimedChannels_enabledRivalChannels_areClaimed(): void
    {
        $subject = $this->paymentMethod(null, true, []);
        $rival = $this->paymentMethod(7, true, ['WEB_FR', 'WEB_BE'], 'CB 1');

        $this->paymentMethodRepository
            ->expects(self::once())
            ->method('findEnabledByGatewayName')
            ->with(PayPlugGatewayFactory::FACTORY_NAME)
            ->willReturn([$rival])
        ;

        $claimed = $this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME);

        self::assertSame(['WEB_FR', 'WEB_BE'], array_keys($claimed));
        self::assertSame('CB 1', $claimed['WEB_FR']->getName());
        self::assertSame('CB 1', $claimed['WEB_BE']->getName());
    }

    public function testFindClaimedChannels_severalRivals_areAllReported(): void
    {
        $subject = $this->paymentMethod(null, true, []);
        $firstRival = $this->paymentMethod(7, true, ['WEB_FR'], 'CB 1');
        $secondRival = $this->paymentMethod(8, true, ['WEB_IT'], 'CB 2');

        $this->paymentMethodRepository
            ->method('findEnabledByGatewayName')
            ->willReturn([$firstRival, $secondRival])
        ;

        $claimed = $this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME);

        self::assertSame('CB 1', $claimed['WEB_FR']->getName());
        self::assertSame('CB 2', $claimed['WEB_IT']->getName());
    }

    /**
     * The channels the edited gateway already holds must stay selectable: a disabled checkbox is
     * not submitted by the browser, so disabling a *checked* one would silently drop the channel
     * on save. Leaving it selectable lets the POST_SUBMIT rule report the conflict instead.
     */
    public function testFindClaimedChannels_rivalIsTheSubjectItself_isNotClaimed(): void
    {
        $subject = $this->paymentMethod(7, true, ['WEB_FR']);
        $itself = $this->paymentMethod(7, true, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$itself]);

        self::assertSame([], $this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    /**
     * Same guarantee as above, for the case the id comparison cannot catch: a *different* enabled
     * payment method already holds a channel the subject also holds. Reachable through the admin
     * alone — create A disabled on a channel (a disabled subject is exempt from `findConflicts()`),
     * then create B enabled on it (A, being disabled, is neither a claim nor a conflict). Editing A
     * afterwards must not disable its own checked box, or saving A silently drops the channel.
     *
     * The rival's other channels stay claimed.
     */
    public function testFindClaimedChannels_channelTheSubjectAlreadyHolds_isNotClaimed(): void
    {
        $subject = $this->paymentMethod(5, true, ['WEB_FR']);
        $rival = $this->paymentMethod(6, true, ['WEB_FR', 'WEB_BE'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        $claimed = $this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME);

        self::assertSame(['WEB_BE'], array_keys($claimed));
        self::assertSame('CB 1', $claimed['WEB_BE']->getName());
    }

    public function testFindClaimedChannels_rivalIsDisabled_isNotClaimed(): void
    {
        $subject = $this->paymentMethod(null, true, []);
        $rival = $this->paymentMethod(7, false, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        self::assertSame([], $this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME));
    }

    /**
     * Unlike `findConflicts()`, the picker is rendered before the admin has decided anything: the
     * claimed set must not depend on the subject's own `enabled` flag or channel selection.
     */
    public function testFindClaimedChannels_subjectIsDisabled_stillReportsClaims(): void
    {
        $subject = $this->paymentMethod(null, false, []);
        $rival = $this->paymentMethod(7, true, ['WEB_FR'], 'CB 1');

        $this->paymentMethodRepository->method('findEnabledByGatewayName')->willReturn([$rival]);

        self::assertSame(
            ['WEB_FR'],
            array_keys($this->checker->findClaimedChannels($subject, PayPlugGatewayFactory::FACTORY_NAME)),
        );
    }

    public function testFindClaimedChannels_queriesOnlyTheGivenFactory(): void
    {
        $subject = $this->paymentMethod(null, true, ['WEB_FR']);

        $this->paymentMethodRepository
            ->expects(self::once())
            ->method('findEnabledByGatewayName')
            ->with(OneyGatewayFactory::FACTORY_NAME)
            ->willReturn([])
        ;

        self::assertSame([], $this->checker->findClaimedChannels($subject, OneyGatewayFactory::FACTORY_NAME));
    }

    /**
     * @param list<string> $channelCodes
     *
     * @return PaymentMethodInterface&MockObject
     */
    private function paymentMethod(
        ?int $id,
        bool $enabled,
        array $channelCodes,
        string $name = 'CB',
    ): PaymentMethodInterface {
        $channels = [];
        foreach ($channelCodes as $channelCode) {
            $channel = $this->createMock(ChannelInterface::class);
            $channel->method('getCode')->willReturn($channelCode);
            $channels[] = $channel;
        }

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getId')->willReturn($id);
        $paymentMethod->method('isEnabled')->willReturn($enabled);
        $paymentMethod->method('getName')->willReturn($name);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection($channels));

        return $paymentMethod;
    }
}
