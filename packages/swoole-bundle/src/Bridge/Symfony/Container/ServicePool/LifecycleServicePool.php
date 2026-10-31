<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

/**
 * @internal
 * @template T of object
 * @extends ServicePool<T>
 */
interface LifecycleServicePool extends ServicePool
{
    /** Keep the assignment accessible; retain failed reset state until release. */
    public function resetAssigned(int $cId): void;

    /** Release once, discarding any assignment that was not successfully reset. */
    public function releaseAssigned(int $cId): void;

    /** Destroy unassigned instances without touching another coroutine's assignment. */
    public function drainFree(): void;
}
