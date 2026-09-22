<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\ILock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class SyliusUpcLock implements ILock
{
    /** @var array<string, LockInterface> */
    private array $locks = [];

    public function __construct(private LockFactory $lockFactory)
    {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        $lock = $this->lockFactory->createLock($key, $ttlSeconds);
        if (!$lock->acquire(false)) {
            return false;
        }

        $this->locks[$key] = $lock;

        return true;
    }

    public function release(string $key): void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }
}
