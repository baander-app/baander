<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\DBAL;

use Doctrine\DBAL\Connection;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALAliveKeeper;

final class OptimizedDBALAliveKeeper implements DBALAliveKeeper
{
    private int $lastPingAt;

    public function __construct(
        private readonly DBALAliveKeeper $decorated,
        private readonly int $pingIntervalInSeconds = 0,
    ) {
        $this->lastPingAt = 0;
    }

    public function keepAlive(Connection $connection, string $connectionName): void
    {
        if (!$this->isPingNeeded()) {
            return;
        }

        $this->decorated->keepAlive($connection, $connectionName);
    }

    private function isPingNeeded(): bool
    {
        $lastPingAt = $this->lastPingAt;
        $now = time();
        $this->lastPingAt = $now;

        return $now - $lastPingAt >= $this->pingIntervalInSeconds;
    }
}
