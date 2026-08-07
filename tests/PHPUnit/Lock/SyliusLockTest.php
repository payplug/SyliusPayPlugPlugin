<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Lock;

use PayPlug\SyliusPayPlugPlugin\Lock\SyliusLock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class SyliusLockTest extends TestCase
{
    private LockFactory&MockObject $lockFactory;

    private SyliusLock $lock;

    protected function setUp(): void
    {
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = new SyliusLock($this->lockFactory);
    }

    // -------------------------------------------------------------------------
    // acquire() — lock is free vs already held
    // -------------------------------------------------------------------------

    public function testAcquire_whenLockIsFree_returnsTrue(): void
    {
        $symfonyLock = $this->createMock(LockInterface::class);
        $symfonyLock->expects(self::once())->method('acquire')->willReturn(true);

        $this->lockFactory->expects(self::once())
            ->method('createLock')
            ->with('operation_123', 30)
            ->willReturn($symfonyLock)
        ;

        self::assertTrue($this->lock->acquire('operation_123', 30));
    }

    public function testAcquire_whenLockIsAlreadyHeld_returnsFalse(): void
    {
        $symfonyLock = $this->createMock(LockInterface::class);
        $symfonyLock->method('acquire')->willReturn(false);

        $this->lockFactory->method('createLock')->willReturn($symfonyLock);

        self::assertFalse($this->lock->acquire('operation_123', 30));
    }

    // -------------------------------------------------------------------------
    // release() — only releases a lock this instance actually acquired
    // -------------------------------------------------------------------------

    public function testRelease_afterSuccessfulAcquire_releasesTheUnderlyingLock(): void
    {
        $symfonyLock = $this->createMock(LockInterface::class);
        $symfonyLock->method('acquire')->willReturn(true);
        $symfonyLock->expects(self::once())->method('release');

        $this->lockFactory->method('createLock')->willReturn($symfonyLock);

        $this->lock->acquire('operation_123', 30);
        $this->lock->release('operation_123');
    }

    public function testRelease_whenKeyWasNeverAcquired_doesNothing(): void
    {
        $this->lockFactory->expects(self::never())->method('createLock');

        $this->lock->release('never_acquired');
    }

    public function testRelease_whenAcquireFailed_doesNotTryToReleaseIt(): void
    {
        $symfonyLock = $this->createMock(LockInterface::class);
        $symfonyLock->method('acquire')->willReturn(false);
        $symfonyLock->expects(self::never())->method('release');

        $this->lockFactory->method('createLock')->willReturn($symfonyLock);

        $this->lock->acquire('operation_123', 30);
        $this->lock->release('operation_123');
    }
}
