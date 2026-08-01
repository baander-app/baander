<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\DBAL;

use Doctrine\DBAL\Connection;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALAliveKeeper;
use Symfony\Component\VarExporter\LazyObjectInterface;

final readonly class PassiveIgnoringDBALAliveKeeper implements DBALAliveKeeper
{
    public function __construct(
        private DBALAliveKeeper $decorated,
    ) {}

    public function keepAlive(Connection $connection, string $connectionName): void
    {
        if ($connection instanceof LazyObjectInterface && !$connection->isLazyObjectInitialized()) {
            return;
        }

        if (!$connection->isConnected()) {
            return;
        }

        $this->decorated->keepAlive($connection, $connectionName);
    }
}
