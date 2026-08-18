<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\SyliusUpcLock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class SyliusUpcLockTest extends TestCase
{
    private LockFactory&MockObject $lockFactory;

    private SyliusUpcLock $lock;

    protected function setUp(): void
    {
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = new SyliusUpcLock($this->lockFactory);
    }

    public function testAcquire_whenLockIsFree_returnsTrue(): void
    {
        $lockInterface = $this->createMock(SharedLockInterface::class);
        $lockInterface->method('acquire')->with(false)->willReturn(true);
        $this->lockFactory->method('createLock')->with('key', 30)->willReturn($lockInterface);

        self::assertTrue($this->lock->acquire('key', 30));
    }

    public function testAcquire_whenLockIsHeld_returnsFalse(): void
    {
        $lockInterface = $this->createMock(SharedLockInterface::class);
        $lockInterface->method('acquire')->with(false)->willReturn(false);
        $this->lockFactory->method('createLock')->willReturn($lockInterface);

        self::assertFalse($this->lock->acquire('key', 30));
    }

    public function testRelease_releasesAPreviouslyAcquiredLock(): void
    {
        $lockInterface = $this->createMock(SharedLockInterface::class);
        $lockInterface->method('acquire')->willReturn(true);
        $lockInterface->expects(self::once())->method('release');
        $this->lockFactory->method('createLock')->willReturn($lockInterface);

        $this->lock->acquire('key', 30);
        $this->lock->release('key');
    }

    public function testRelease_whenNothingWasAcquired_doesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->lock->release('never-acquired');
    }
}
