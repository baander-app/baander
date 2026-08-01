<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL;

use Doctrine\DBAL\Connection;

interface DBALAliveKeeper
{
    public function keepAlive(Connection $connection, string $connectionName): void;
}
