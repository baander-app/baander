<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

interface PoolStatsInterface
{
    public function getAssignedCount(): int;

    public function getFreeCount(): int;

    public function getInstancesLimit(): int;
}
