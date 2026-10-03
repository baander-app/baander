<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted-controller retirement journal on a dedicated idle autocommit PostgreSQL connection. */
#[Exclude]
final class DoctrineDeploymentRetirement
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Deployment retirement requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    public function find(string $namespace, string $bootId): ?DeploymentRetirement
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentRetirement => $this->select($namespace, $bootId));
    }

    /** Call only after trusted isolation verification. Exact retries reconcile uncertain commits without rebinding. */
    public function begin(DeploymentContainer $binding): bool
    {
        return $this->operation(function () use ($binding): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_retirements (namespace, boot_id, daemon_id, container_id)
                SELECT namespace, boot_id, daemon_id, container_id FROM worker_deployment_containers
                WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon AND container_id = :container
                ON CONFLICT DO NOTHING
                SQL, $this->parameters($binding));
            $intent = $this->select($binding->namespace, $binding->bootId);
            return $intent !== null && $this->matches($intent->binding, $binding);
        });
    }

    /** Call only after trusted removal/absence on the pinned daemon. Completion and own-boot lease release commit together. */
    public function complete(DeploymentContainer $binding): bool
    {
        return $this->operation(function () use ($binding): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            $intent = $this->select($binding->namespace, $binding->bootId);
            if ($intent === null || !$this->matches($intent->binding, $binding)) {
                return false;
            }
            if ($intent->completed) {
                return true;
            }
            // Acquisition must commit before begin can pass its shared barrier.
            // The tombstone thereafter forbids all epochs for this boot, so the
            // current same-boot row is the only owner that can need release.
            $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_leases SET state = 'available'
                WHERE namespace = :namespace AND owner_boot_id = :boot AND state = 'active'
                SQL, ['namespace' => $binding->namespace, 'boot' => $binding->bootId]);
            $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_retirements SET completed_at = clock_timestamp()
                WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon AND container_id = :container
                    AND completed_at IS NULL
                SQL, $this->parameters($binding));
            return true;
        });
    }

    private function select(string $namespace, string $bootId): ?DeploymentRetirement
    {
        $row = $this->connection->fetchAssociative('SELECT daemon_id, container_id, completed_at FROM worker_deployment_retirements WHERE namespace = :namespace AND boot_id = :boot', ['namespace' => $namespace, 'boot' => $bootId]);
        return $row === false ? null : new DeploymentRetirement(new DeploymentContainer($namespace, $bootId, $row['daemon_id'], $row['container_id']), $row['completed_at'] !== null);
    }

    private function matches(DeploymentContainer $left, DeploymentContainer $right): bool
    {
        return $left->namespace === $right->namespace && $left->bootId === $right->bootId
            && $left->daemonId === $right->daemonId && $left->containerId === $right->containerId;
    }

    /** @return array{namespace: string, boot: string, daemon: string, container: string} */
    private function parameters(DeploymentContainer $binding): array
    {
        return ['namespace' => $binding->namespace, 'boot' => $binding->bootId, 'daemon' => $binding->daemonId, 'container' => $binding->containerId];
    }

    /**
     * No database transaction spans Docker; connection/commit I/O has no SQL timeout bound.
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Deployment retirement requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->connection->executeQuery("SELECT set_config('statement_timeout', :statement, true), set_config('lock_timeout', :lock, true)", [
                'statement' => $this->statementTimeoutMs . 'ms', 'lock' => $this->lockTimeoutMs . 'ms',
            ])->free();
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the operation error when rollback is uncertain.
            }
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // Discard failed/unknown commit state without replacing its error.
            }
            throw $error;
        }
    }
}
