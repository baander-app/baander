<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Locking\FirstTimeOnly;

use Assert\Assertion;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;

final class FirstTimeOnlyMutex implements Mutex
{
    private const int NEW = 0;

    private const int LOCKED = 1;

    private const int RELEASED = 2;

    private int $lockState = self::NEW;

    public function __construct(private ?Mutex $wrapped) {}

    public function acquire(): void
    {
        if ($this->lockState === self::RELEASED) {
            return;
        }

        $wrapped = $this->wrapped;
        Assertion::notNull($wrapped);

        if ($this->lockState === self::NEW) {
            $this->lockState = self::LOCKED;
            $wrapped->acquire();

            return;
        }

        $wrapped->acquire();
        $wrapped->release();
    }

    public function release(): void
    {
        if ($this->lockState !== self::LOCKED) {
            return;
        }

        $wrapped = $this->wrapped;
        Assertion::notNull($wrapped);

        // Releasing the channel can resume a waiter immediately.
        $this->lockState = self::RELEASED;
        $this->wrapped = null;
        $wrapped->release();
    }

    public function isAcquired(): bool
    {
        return $this->wrapped !== null && $this->wrapped->isAcquired();
    }
}
