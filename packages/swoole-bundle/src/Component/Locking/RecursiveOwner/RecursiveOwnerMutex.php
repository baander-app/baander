<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner;

use Assert\Assertion;
use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;

final class RecursiveOwnerMutex implements Mutex
{
    private const int NO_OWNER = -2;

    /**
     * References the canonical multiplier from Swoole for thread extraction via intdiv().
     * @see \SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole::CONTEXT_KEY_MULTIPLIER
     */
    private const int CONTEXT_KEY_MULTIPLIER = Swoole::CONTEXT_KEY_MULTIPLIER;

    private int $ownerId = self::NO_OWNER;

    private int $currentOwnerUsageCount = 0;

    public function __construct(
        private readonly Swoole $swoole,
        private readonly ?Mutex $wrapped,
    ) {}

    public function acquire(): void
    {
        $possibleOwnerId = $this->swoole->getCoroutineId();

        if ($this->canBeAcquired($possibleOwnerId)) {
            if (!$this->isAcquired()) {
                Assertion::notNull($this->wrapped);
                $this->wrapped->acquire();
                $this->ownerId = $possibleOwnerId;
            }
            ++$this->currentOwnerUsageCount;

            return;
        }

        Assertion::notNull($this->wrapped);
        $this->wrapped->acquire();
        $this->ownerId = $possibleOwnerId;
        ++$this->currentOwnerUsageCount;
    }

    public function release(): void
    {
        $possibleOwnerId = $this->swoole->getCoroutineId();

        // When running outside a coroutine context (e.g. kernel boot before
        // Swoole workers fork), getCoroutineId() returns -1. Allow release
        // in this case — the lock was acquired in the same non-coroutine path.
        if ($possibleOwnerId === -1 && $this->isAcquired()) {
            $possibleOwnerId = $this->ownerId;
        }

        // Defense-in-depth: explicit cross-thread violation check.
        // The composite key already prevents cross-thread release via ownership
        // mismatch, but this catches the scenario early with a descriptive error.
        if ($this->swoole->isThreadMode() && $this->isAcquired() && $possibleOwnerId !== $this->ownerId) {
            $ownerThreadId = intdiv($this->ownerId, self::CONTEXT_KEY_MULTIPLIER);
            $currentThreadId = $this->swoole->getThreadId();
            if ($ownerThreadId !== $currentThreadId && $ownerThreadId !== 0) {
                throw new RuntimeException(sprintf(
                    'Cross-thread mutex violation: lock owned by thread %d (ownerId=%d), release attempted by thread %d.',
                    $ownerThreadId,
                    $this->ownerId,
                    $currentThreadId,
                ));
            }
        }

        if (!$this->isOwnedBy($possibleOwnerId)) {
            throw new RuntimeException(sprintf('Mutex cannot be released by %d.', $possibleOwnerId));
        }

        --$this->currentOwnerUsageCount;

        if ($this->currentOwnerUsageCount !== 0) {
            return;
        }

        $this->ownerId = self::NO_OWNER;
        Assertion::notNull($this->wrapped);
        $this->wrapped->release();
    }

    public function isAcquired(): bool
    {
        return $this->ownerId !== self::NO_OWNER;
    }

    private function canBeAcquired(int $possibleOwnerId): bool
    {
        return !$this->isAcquired() || $this->isOwnedBy($possibleOwnerId);
    }

    private function isOwnedBy(int $possibleOwnerId): bool
    {
        return $this->ownerId === $possibleOwnerId;
    }
}
