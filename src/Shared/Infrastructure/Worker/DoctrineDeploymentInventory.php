<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable bindings and irreversible start claims, on a dedicated idle autocommit PostgreSQL connection. */
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

    /** Commit one creation attempt before contacting Docker. Uncertain outcomes never permit a retry. */
    public function claimCreate(DeploymentContainerRecipe $recipe): bool
    {
        return $this->operation(fn (): bool => $this->connection->executeStatement(<<<'SQL'
            INSERT INTO worker_deployment_creations (namespace, boot_id, daemon_id, recipe_hash)
            VALUES (:namespace, :boot, :daemon, :recipe)
            ON CONFLICT DO NOTHING
            SQL, ['namespace' => $recipe->namespace, 'boot' => $recipe->bootId, 'daemon' => $recipe->daemonId, 'recipe' => $recipe->fingerprint()]) === 1);
    }

    /** Reconciliation may observe the exact reserved recipe; matching never grants another create attempt. */
    public function matchesCreate(DeploymentContainerRecipe $recipe): bool
    {
        return $this->operation(fn (): bool => $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM worker_deployment_creations
            WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon AND recipe_hash = :recipe
            SQL, ['namespace' => $recipe->namespace, 'boot' => $recipe->bootId, 'daemon' => $recipe->daemonId, 'recipe' => $recipe->fingerprint()]) !== false);
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

    /**
     * Commit one start permission for this exact binding. Only acknowledged true
     * permits one Docker start attempt. Never reset or retry after uncertainty,
     * even when container inspection shows it has not started or already exited.
     */
    public function claimStart(DeploymentContainer $container): bool
    {
        return $this->operation(function () use ($container): bool {
            DeploymentNamespaceLock::acquire($this->connection, $container->namespace);
            if ($this->connection->fetchOne('SELECT 1 FROM worker_deployment_retirements WHERE namespace = :namespace AND boot_id = :boot', ['namespace' => $container->namespace, 'boot' => $container->bootId]) !== false) {
                return false;
            }
            return $this->connection->executeStatement(<<<'SQL'
            UPDATE worker_deployment_containers SET start_claimed_at = clock_timestamp()
            WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon
                AND container_id = :container AND start_claimed_at IS NULL
            SQL, ['namespace' => $container->namespace, 'boot' => $container->bootId, 'daemon' => $container->daemonId, 'container' => $container->containerId]) === 1;
        });
    }

    public function find(string $namespace, string $bootId): ?DeploymentContainer
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentContainer => $this->select($namespace, $bootId));
    }

    /** Observes the irreversible claim; it never grants permission to issue a start. */
    public function hasStartClaim(DeploymentContainer $binding): bool
    {
        return $this->operation(fn (): bool => $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM worker_deployment_containers
            WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon
                AND container_id = :container AND start_claimed_at IS NOT NULL
            SQL, ['namespace' => $binding->namespace, 'boot' => $binding->bootId,
                'daemon' => $binding->daemonId, 'container' => $binding->containerId]) !== false);
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
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
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
