<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine;

use App\Scheduler\Application\Port\SchedulerWorkerAuthorityInterface;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Scheduler preflight on a dedicated connection; never acquires, renews or releases a lease. */
#[Exclude]
final class DoctrineSchedulerWorkerAuthority implements SchedulerWorkerAuthorityInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?DeploymentLease $authority = null,
        private readonly int $statementTimeoutMs = 1000,
        private readonly int $lockTimeoutMs = 250,
    ) {
        if ($lockTimeoutMs < 1 || $statementTimeoutMs < $lockTimeoutMs || $statementTimeoutMs > 60000) {
            throw new \InvalidArgumentException('Scheduler authority requires positive lock/statement timeouts bounded to 60 seconds.');
        }
    }

    public static function fromDsn(string $databaseUrl): self
    {
        if ($databaseUrl === '' || str_contains($databaseUrl, "\0")) {
            throw new \InvalidArgumentException('Scheduler authority requires an explicit database URL.');
        }
        $authority = null;
        if (getenv('BAANDER_WORKER_ID') === 'scheduler') {
            $namespace = getenv('BAANDER_WORKER_NAMESPACE');
            $bootId = getenv('BAANDER_WORKER_BOOT_ID');
            $epoch = getenv('BAANDER_WORKER_LEASE_EPOCH');
            if ($namespace !== false && $bootId !== false && $epoch !== false) {
                $epochValue = filter_var($epoch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                try {
                    if (preg_match('/\A[1-9][0-9]*\z/D', $epoch) !== 1 || $epochValue === false) {
                        throw new \InvalidArgumentException();
                    }
                    $authority = new DeploymentLease($namespace, $bootId, $epochValue);
                } catch (\InvalidArgumentException) {
                    throw new \RuntimeException('Scheduler worker authority context is invalid.');
                }
            }
        }
        return new self(DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl)), $authority);
    }

    /** Statement/lock timeouts do not bound connection setup, network I/O or commit. */
    public function assertActive(): void
    {
        $authority = $this->authority;
        if ($authority === null) {
            throw new \RuntimeException('Scheduler worker requires active deployment authority.');
        }
        if (!$this->connection->isAutoCommit() || $this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Scheduler authority requires a dedicated idle autocommit connection.');
        }
        try {
            $this->connection->beginTransaction();
            $this->connection->executeQuery("SELECT set_config('statement_timeout', :statement, true), set_config('lock_timeout', :lock, true)", ['statement' => $this->statementTimeoutMs . 'ms', 'lock' => $this->lockTimeoutMs . 'ms'])->free();
            // A plain read checks committed authority without delaying lease replacement.
            $active = $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM worker_deployment_leases
                WHERE namespace = :namespace AND owner_boot_id = :boot AND epoch = :epoch
                    AND state = 'active' AND expires_at > clock_timestamp()
                SQL, ['namespace' => $authority->namespace, 'boot' => $authority->bootId, 'epoch' => $authority->epoch]) !== false;
            if (!$active) {
                throw new \RuntimeException('Scheduler worker requires active deployment authority.');
            }
            $this->connection->commit();
        } catch (\Throwable $error) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the authority-check error if rollback is also uncertain.
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
