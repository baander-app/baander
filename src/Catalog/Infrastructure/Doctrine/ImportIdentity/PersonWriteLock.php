<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class PersonWriteLock
{
    public static function shared(Connection $connection): void
    {
        self::acquire($connection, false);
    }

    public static function exclusive(Connection $connection): void
    {
        self::acquire($connection, true);
    }

    private static function acquire(Connection $connection, bool $exclusive): void
    {
        if (!$connection->isTransactionActive()) {
            throw new LogicException('Person writes need a transaction.');
        }

        // Imports share this gate. Reconciliation takes it exclusively before moving any
        // IDs, so a writer cannot discover an owner that is deleted partway through its write.
        $function = $exclusive ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
        $connection->executeQuery("SELECT {$function}(20261121194501)")->free();
    }
}
