<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * DBAL-owned migration table, using a dedicated idle autocommit connection.
 * Expiry revokes renewal, never authorizes takeover. This primitive neither
 * verifies Docker containment nor fences business writes or external effects.
 * SQL statement/lock timeouts do not bound connection setup, network I/O or commit.
 */
#[Exclude]
final class DoctrineDeploymentLease
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Deployment lease requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    /** A null result includes duplicate same-boot claims; uncertain outcomes require reconciliation, not blind retries. */
    public function acquire(string $namespace, string $bootId, int $ttlSeconds): ?DeploymentLease
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        $this->validateTtl($ttlSeconds);
        $row = $this->operation(function () use ($namespace, $bootId, $ttlSeconds): array|false {
            DeploymentNamespaceLock::acquire($this->connection, $namespace);
            if ($this->connection->fetchOne('SELECT 1 FROM worker_deployment_retirements WHERE namespace = :namespace AND boot_id = :boot', ['namespace' => $namespace, 'boot' => $bootId]) !== false) {
                return false;
            }
            return $this->connection->fetchAssociative(<<<'SQL'
            INSERT INTO worker_deployment_leases (namespace, owner_boot_id, epoch, state, expires_at)
            VALUES (:namespace, :boot, 1, 'active', clock_timestamp() + make_interval(secs => :ttl))
            ON CONFLICT (namespace) DO UPDATE SET owner_boot_id = EXCLUDED.owner_boot_id,
                epoch = worker_deployment_leases.epoch + 1, state = 'active', expires_at = clock_timestamp() + make_interval(secs => :ttl)
            WHERE worker_deployment_leases.state = 'available'
            RETURNING epoch
            SQL, ['namespace' => $namespace, 'boot' => $bootId, 'ttl' => $ttlSeconds]);
        });

        return $row === false ? null : new DeploymentLease($namespace, $bootId, (int) $row['epoch']);
    }

    /** Trusted-controller reconciliation only; observing a token never grants admission or containment. */
    public function findForContainment(string $namespace): ?DeploymentLease
    {
        DeploymentLease::validateIdentity($namespace, str_repeat('0', 32));
        $row = $this->operation(fn (): array|false => $this->connection->fetchAssociative(
            "SELECT owner_boot_id, epoch FROM worker_deployment_leases WHERE namespace = :namespace AND state = 'active'",
            ['namespace' => $namespace],
        ));
        return $row === false ? null : new DeploymentLease($namespace, $row['owner_boot_id'], (int) $row['epoch']);
    }

    public function renew(DeploymentLease $lease, int $ttlSeconds): bool
    {
        $this->validateTtl($ttlSeconds);
        return $this->operation(function () use ($lease, $ttlSeconds): int {
            // Acquire the row lock before evaluating expiry. An UPDATE predicate
            // alone can be evaluated before waiting for an unchanged locked row.
            $params = ['namespace' => $lease->namespace, 'boot' => $lease->bootId, 'epoch' => $lease->epoch];
            if ($this->connection->fetchOne(<<<'SQL'
                SELECT namespace FROM worker_deployment_leases
                WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch AND state = 'active'
                FOR UPDATE
                SQL, $params) === false) {
                return 0;
            }
            return $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_leases SET expires_at = clock_timestamp() + make_interval(secs => :ttl)
                WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch
                    AND state = 'active' AND expires_at > clock_timestamp()
                SQL, [...$params, 'ttl' => $ttlSeconds]);
        }) === 1;
    }

    /** Only a trusted controller may call this AFTER verifying predecessor cleanup; elapsed TTL is insufficient. */
    public function acknowledgeContainment(DeploymentLease $lease): bool
    {
        return $this->operation(fn (): int => $this->connection->executeStatement(<<<'SQL'
            UPDATE worker_deployment_leases SET state = 'available'
            WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch AND state = 'active'
            SQL, ['namespace' => $lease->namespace, 'boot' => $lease->bootId, 'epoch' => $lease->epoch])) === 1;
    }

    private function validateTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 3600) {
            throw new \InvalidArgumentException('Deployment lease TTL must be between one second and one hour.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function operation(\Closure $operation): mixed
    {
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Deployment lease requires a dedicated idle autocommit connection.');
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
                // Cleanup must not replace the original operation error.
            }
            try {
                // DBAL nesting is not evidence of driver state after a failed
                // begin/commit. Discard even when the commit outcome is unknown.
                $this->connection->close();
            } catch (\Throwable) {
                // Preserve the original exception if discarding also fails.
            }
            throw $error;
        }
    }
}
