<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Trusted controller records unit proofs; only joint boot completion releases ownership. */
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

    public function findUnit(string $namespace, string $bootId, DeploymentUnit $unit): ?DeploymentRetirement
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentRetirement => $this->select($namespace, $bootId, $unit));
    }

    /** Cancellation is safe only for units whose create claim never committed. */
    public function requestRetirement(string $namespace, string $bootId): bool
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(function () use ($namespace, $bootId): bool {
            DeploymentNamespaceLock::acquire($this->connection, $namespace);
            $params = ['namespace' => $namespace, 'boot' => $bootId];
            if ($this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_boots SET retirement_requested_at = COALESCE(retirement_requested_at, clock_timestamp())
                WHERE namespace = :namespace AND boot_id = :boot
                SQL, $params) !== 1) {
                return false;
            }
            $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_creations SET cancelled_at = COALESCE(cancelled_at, clock_timestamp())
                WHERE namespace = :namespace AND boot_id = :boot AND claimed_at IS NULL
                SQL, $params);
            return true;
        });
    }

    /** Call after trusted isolation verification of the exact reserved immutable binding. */
    public function beginUnitRetirement(DeploymentContainer $binding): bool
    {
        return $this->operation(function () use ($binding): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_retirements (namespace, boot_id, unit, daemon_id, container_id)
                SELECT i.namespace, i.boot_id, i.unit, i.daemon_id, i.container_id
                FROM worker_deployment_containers i JOIN worker_deployment_boots b USING (namespace, boot_id, daemon_id)
                WHERE i.namespace = :namespace AND i.boot_id = :boot AND i.unit = :unit AND i.daemon_id = :daemon AND i.container_id = :container
                    AND b.retirement_requested_at IS NOT NULL
                ON CONFLICT DO NOTHING
                SQL, $this->parameters($binding));
            $intent = $this->select($binding->namespace, $binding->bootId, $binding->unit);
            return $intent !== null && $intent->binding->matches($binding);
        });
    }

    /** Trusted exact-ID absence proof for this unit only; never makes the namespace available. */
    public function completeUnitRetirement(DeploymentContainer $binding): bool
    {
        return $this->operation(function () use ($binding): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            return $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_retirements SET completed_at = COALESCE(completed_at, clock_timestamp())
                WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit AND daemon_id = :daemon AND container_id = :container
                SQL, $this->parameters($binding)) === 1;
        });
    }

    /** Root completion and release commit together; delayed old completion cannot release a newer boot. */
    public function completeBootRetirement(string $namespace, string $bootId): bool
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(function () use ($namespace, $bootId): bool {
            DeploymentNamespaceLock::acquire($this->connection, $namespace);
            $params = ['namespace' => $namespace, 'boot' => $bootId];
            $root = $this->connection->fetchAssociative('SELECT retirement_requested_at, retired_at FROM worker_deployment_boots WHERE namespace = :namespace AND boot_id = :boot', $params);
            if ($root === false || $root['retirement_requested_at'] === null) {
                return false;
            }
            if ($root['retired_at'] !== null) {
                return true;
            }
            if ((int) $this->connection->fetchOne('SELECT count(*) FROM worker_deployment_creations WHERE namespace = :namespace AND boot_id = :boot', $params) !== 2
                || $this->connection->fetchOne(<<<'SQL'
                    SELECT 1 FROM worker_deployment_creations c
                    WHERE c.namespace = :namespace AND c.boot_id = :boot AND NOT (
                        (c.claimed_at IS NULL AND c.cancelled_at IS NOT NULL)
                        OR EXISTS (
                            SELECT 1 FROM worker_deployment_containers i
                            JOIN worker_deployment_retirements r USING (namespace, boot_id, unit, daemon_id, container_id)
                            WHERE i.namespace = c.namespace AND i.boot_id = c.boot_id AND i.unit = c.unit AND i.daemon_id = c.daemon_id
                                AND r.completed_at IS NOT NULL
                        )
                    )
                    SQL, $params) !== false) {
                return false;
            }
            $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_boots SET retired_at = clock_timestamp() WHERE namespace = :namespace AND boot_id = :boot
                SQL, $params);
            $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_leases SET state = 'available'
                WHERE namespace = :namespace AND owner_boot_id = :boot AND state = 'active'
                SQL, $params);
            return true;
        });
    }

    private function select(string $namespace, string $bootId, DeploymentUnit $unit): ?DeploymentRetirement
    {
        $row = $this->connection->fetchAssociative('SELECT daemon_id, container_id, completed_at FROM worker_deployment_retirements WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit', ['namespace' => $namespace, 'boot' => $bootId, 'unit' => $unit->value]);
        return $row === false ? null : new DeploymentRetirement(new DeploymentContainer($namespace, $bootId, $row['daemon_id'], $row['container_id'], $unit), $row['completed_at'] !== null);
    }

    /** @return array{namespace:string, boot:string, unit:string, daemon:string, container:string} */
    private function parameters(DeploymentContainer $binding): array
    {
        return ['namespace' => $binding->namespace, 'boot' => $binding->bootId, 'unit' => $binding->unit->value, 'daemon' => $binding->daemonId, 'container' => $binding->containerId];
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
