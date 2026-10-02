<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Append-only DBAL inventory, on a dedicated idle autocommit PostgreSQL connection. */
#[Exclude]
final class DoctrineDeploymentInventory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Deployment inventory requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    /** Register after creating a stopped container, before starting it. Exact retries are idempotent; no rebind is permitted. */
    public function register(DeploymentContainer $container): bool
    {
        return $this->operation(function () use ($container): bool {
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_containers (namespace, boot_id, daemon_id, container_id)
                VALUES (:namespace, :boot, :daemon, :container)
                ON CONFLICT DO NOTHING
                SQL, ['namespace' => $container->namespace, 'boot' => $container->bootId, 'daemon' => $container->daemonId, 'container' => $container->containerId]);
            $registered = $this->select($container->namespace, $container->bootId);
            return $registered !== null && $registered->daemonId === $container->daemonId && $registered->containerId === $container->containerId;
        });
    }

    public function find(string $namespace, string $bootId): ?DeploymentContainer
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentContainer => $this->select($namespace, $bootId));
    }

    private function select(string $namespace, string $bootId): ?DeploymentContainer
    {
        $row = $this->connection->fetchAssociative('SELECT daemon_id, container_id FROM worker_deployment_containers WHERE namespace = :namespace AND boot_id = :boot', ['namespace' => $namespace, 'boot' => $bootId]);
        return $row === false ? null : new DeploymentContainer($namespace, $bootId, $row['daemon_id'], $row['container_id']);
    }

    /**
     * Statement/lock timeouts do not bound connection setup, network I/O or commit.
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Deployment inventory requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeQuery("SELECT set_config('statement_timeout', :statement, true), set_config('lock_timeout', :lock, true)", ['statement' => $this->statementTimeoutMs . 'ms', 'lock' => $this->lockTimeoutMs . 'ms'])->free();
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the operation error if rollback is also uncertain.
            }
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // Failed begin/commit nesting is not authoritative driver state.
            }
            throw $error;
        }
    }
}
