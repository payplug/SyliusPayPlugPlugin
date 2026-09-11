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
