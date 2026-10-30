<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

final class SwoolePoolStatsProvider
{
    public function __construct(
        private readonly ServicePoolContainer $poolContainer,
    ) {}

    /**
     * IDs identify retained pool objects only in this worker process; compare them with its PID.
     *
     * @return array<int, array{id: int, active: int, free: int, limit: int}>
     */
    public function getStats(): array
    {
        $stats = [];
        foreach ($this->poolContainer->getPools() as $pool) {
            $stats[] = [
                'id' => spl_object_id($pool),
                'active' => $pool->getAssignedCount(),
                'free' => $pool->getFreeCount(),
                'limit' => $pool->getInstancesLimit(),
            ];
        }

        return $stats;
    }
}
