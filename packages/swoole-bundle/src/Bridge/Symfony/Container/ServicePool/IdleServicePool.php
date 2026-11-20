<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

/**
 * @template T of object
 * @extends ServicePool<T>
 */
interface IdleServicePool extends ServicePool
{
    /** Release expired unassigned instances while retaining warm capacity. */
    public function pruneFree(float $idleSeconds = 60.0, int $minimumFree = 1): int;
}
