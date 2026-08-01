<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALAliveKeeper;

final class PingingDBALAliveKeeper implements DBALAliveKeeper
{
    public function keepAlive(Connection $connection, string $connectionName): void
    {
        $query = $connection->getDatabasePlatform()->getDummySelectSQL();

        try {
            $connection->executeQuery($query);
        } catch (ConnectionLost) {
            $connection->close();
            $connection->getNativeConnection();
        }
    }
}
