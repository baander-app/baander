<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

final class SwoolePoolStatsProvider
{
    public function __construct(
        private readonly ServicePoolContainer $poolContainer,
    )
    {
    }

    /**
     * @return array<int, array{active: int, free: int, limit: int}>
     */
    public function getStats(): array
    {
        $stats = [];
        foreach ($this->poolContainer->getPools() as $i => $pool) {
            $stats[] = [
                'active' => $pool->getAssignedCount(),
                'free' => $pool->getFreeCount(),
                'limit' => $pool->getInstancesLimit(),
            ];
        }

        return $stats;
    }
}
