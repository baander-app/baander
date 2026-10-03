<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Admission and irreversible retirement share this transaction-scoped namespace barrier. */
#[Exclude]
final class DeploymentNamespaceLock
{
    public static function acquire(Connection $connection, string $namespace): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Deployment namespace locks require an active transaction.');
        }
        // Hash collisions only serialize unrelated namespaces; they cannot grant admission.
        $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:namespace, 20261003020000))', ['namespace' => $namespace])->free();
    }
}
