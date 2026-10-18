<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Fixed unit reservations and irreversible claims on a dedicated idle autocommit connection. */
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

    /** Exact retries observe the reservation; they never reset a unit's permission. */
    public function reserveBoot(string $namespace, string $bootId, string $manifestHash, string $daemonId, string $controlRecipeHash, string $executionRecipeHash): bool
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $daemonId)) {
            throw new \InvalidArgumentException('A deployment requires a bounded safe daemon identity.');
        }
        foreach ([$manifestHash, $controlRecipeHash, $executionRecipeHash] as $hash) {
            if (!preg_match('/\A[0-9a-f]{64}\z/D', $hash)) {
                throw new \InvalidArgumentException('Deployment manifests and recipes require SHA-256 fingerprints.');
            }
        }
        return $this->operation(function () use ($namespace, $bootId, $manifestHash, $daemonId, $controlRecipeHash, $executionRecipeHash): bool {
            DeploymentNamespaceLock::acquire($this->connection, $namespace);
            $existing = $this->selectBoot($namespace, $bootId);
            if ($existing !== null) {
                return !$existing->retirementRequested && $existing->manifestHash === $manifestHash && $existing->daemonId === $daemonId
                    && $existing->control->recipeHash === $controlRecipeHash && $existing->execution->recipeHash === $executionRecipeHash;
            }
            if ($this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM worker_deployment_boots WHERE namespace = :namespace AND retired_at IS NULL
                UNION ALL SELECT 1 FROM worker_deployment_leases WHERE namespace = :namespace AND state = 'active'
                SQL, ['namespace' => $namespace]) !== false) {
                return false;
            }
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_boots (namespace, boot_id, daemon_id, manifest_hash) VALUES (:namespace, :boot, :daemon, :manifest)
                SQL, ['namespace' => $namespace, 'boot' => $bootId, 'daemon' => $daemonId, 'manifest' => $manifestHash]);
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_creations (namespace, boot_id, daemon_id, unit, recipe_hash)
                VALUES (:namespace, :boot, :daemon, 'control', :control), (:namespace, :boot, :daemon, 'execution', :execution)
                SQL, ['namespace' => $namespace, 'boot' => $bootId, 'daemon' => $daemonId, 'control' => $controlRecipeHash, 'execution' => $executionRecipeHash]);
            return true;
        });
    }

    /** Only acknowledged true permits one Docker create attempt for the reserved unit. */
    public function claimCreate(string $namespace, string $bootId, DeploymentUnit $unit): bool
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(function () use ($namespace, $bootId, $unit): bool {
            DeploymentNamespaceLock::acquire($this->connection, $namespace);
            return $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_creations c SET claimed_at = clock_timestamp()
                FROM worker_deployment_boots b
                WHERE c.namespace = :namespace AND c.boot_id = :boot AND c.unit = :unit
                    AND b.namespace = c.namespace AND b.boot_id = c.boot_id AND b.retirement_requested_at IS NULL
                    AND c.claimed_at IS NULL AND c.cancelled_at IS NULL AND c.recipe_hash IS NOT NULL
                    AND (SELECT count(*) FROM worker_deployment_creations sibling WHERE sibling.namespace = b.namespace AND sibling.boot_id = b.boot_id) = 2
                SQL, ['namespace' => $namespace, 'boot' => $bootId, 'unit' => $unit->value]) === 1;
        });
    }

    /** Matching committed intent is observation only, including during retirement recovery. */
    public function matchesCreate(string $namespace, string $bootId, DeploymentUnit $unit, string $daemonId, string $recipeHash): bool
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): bool => $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM worker_deployment_creations
            WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit AND daemon_id = :daemon AND recipe_hash = :recipe AND claimed_at IS NOT NULL
            SQL, ['namespace' => $namespace, 'boot' => $bootId, 'unit' => $unit->value, 'daemon' => $daemonId, 'recipe' => $recipeHash]) !== false);
    }

    public function register(DeploymentContainer $binding): bool
    {
        return $this->registerBinding($binding, false);
    }

    /** Caller must verify the reserved recipe; this path grants removal authority only. */
    public function registerForRetirement(DeploymentContainer $binding): bool
    {
        return $this->registerBinding($binding, true);
    }

    private function registerBinding(DeploymentContainer $binding, bool $retirement): bool
    {
        return $this->operation(function () use ($binding, $retirement): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            $params = $this->parameters($binding);
            $boot = $this->connection->fetchAssociative('SELECT retirement_requested_at, retired_at FROM worker_deployment_boots WHERE namespace = :namespace AND boot_id = :boot', ['namespace' => $binding->namespace, 'boot' => $binding->bootId]);
            if ($boot === false || $boot['retired_at'] !== null || (($boot['retirement_requested_at'] !== null) !== $retirement)) {
                return false;
            }
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO worker_deployment_containers (namespace, boot_id, unit, daemon_id, container_id)
                SELECT namespace, boot_id, unit, daemon_id, :container FROM worker_deployment_creations
                WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit AND daemon_id = :daemon
                    AND claimed_at IS NOT NULL AND cancelled_at IS NULL
                ON CONFLICT DO NOTHING
                SQL, $params);
            return $this->selectUnit($binding->namespace, $binding->bootId, $binding->unit)?->matches($binding) === true;
        });
    }

    /** A committed claim permits one start attempt; neither observation nor retries restore it. */
    public function claimStart(DeploymentContainer $binding): bool
    {
        return $this->operation(function () use ($binding): bool {
            DeploymentNamespaceLock::acquire($this->connection, $binding->namespace);
            return $this->connection->executeStatement(<<<'SQL'
                UPDATE worker_deployment_containers c SET start_claimed_at = clock_timestamp()
                FROM worker_deployment_boots b
                WHERE c.namespace = :namespace AND c.boot_id = :boot AND c.unit = :unit AND c.daemon_id = :daemon AND c.container_id = :container
                    AND c.start_claimed_at IS NULL AND b.namespace = c.namespace AND b.boot_id = c.boot_id
                    AND b.retirement_requested_at IS NULL AND b.manifest_hash IS NOT NULL
                    AND (SELECT count(*) FROM worker_deployment_containers sibling WHERE sibling.namespace = b.namespace AND sibling.boot_id = b.boot_id) = 2
                SQL, $this->parameters($binding)) === 1;
        });
    }

    public function findUnit(string $namespace, string $bootId, DeploymentUnit $unit): ?DeploymentContainer
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentContainer => $this->selectUnit($namespace, $bootId, $unit));
    }

    public function findBoot(string $namespace, string $bootId): ?DeploymentBoot
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        return $this->operation(fn (): ?DeploymentBoot => $this->selectBoot($namespace, $bootId));
    }

    public function hasStartClaim(DeploymentContainer $binding): bool
    {
        return $this->operation(fn (): bool => $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM worker_deployment_containers
            WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit AND daemon_id = :daemon AND container_id = :container AND start_claimed_at IS NOT NULL
            SQL, $this->parameters($binding)) !== false);
    }

    private function selectUnit(string $namespace, string $bootId, DeploymentUnit $unit): ?DeploymentContainer
    {
        $row = $this->connection->fetchAssociative('SELECT daemon_id, container_id FROM worker_deployment_containers WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit', ['namespace' => $namespace, 'boot' => $bootId, 'unit' => $unit->value]);
        return $row === false ? null : new DeploymentContainer($namespace, $bootId, $row['daemon_id'], $row['container_id'], $unit);
    }

    private function selectBoot(string $namespace, string $bootId): ?DeploymentBoot
    {
        // A single statement gives a consistent root-and-pair snapshot at READ COMMITTED.
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.*, c.unit, c.recipe_hash, c.claimed_at, c.cancelled_at, i.container_id, i.start_claimed_at,
                r.intended_at, r.completed_at
            FROM worker_deployment_boots b
            JOIN worker_deployment_creations c USING (namespace, boot_id, daemon_id)
            LEFT JOIN worker_deployment_containers i USING (namespace, boot_id, unit, daemon_id)
            LEFT JOIN worker_deployment_retirements r USING (namespace, boot_id, unit, daemon_id, container_id)
            WHERE b.namespace = :namespace AND b.boot_id = :boot
            SQL, ['namespace' => $namespace, 'boot' => $bootId]);
        if ($rows === []) {
            return null;
        }
        $units = [];
        foreach ($rows as $row) {
            $unit = DeploymentUnit::from($row['unit']);
            $binding = $row['container_id'] === null ? null : new DeploymentContainer($namespace, $bootId, $row['daemon_id'], $row['container_id'], $unit);
            $retirement = $row['intended_at'] === null || $binding === null ? null : new DeploymentRetirement($binding, $row['completed_at'] !== null);
            $units[$unit->value] = new DeploymentUnitState($unit, $row['recipe_hash'], $row['claimed_at'] !== null, $row['cancelled_at'] !== null, $binding, $row['start_claimed_at'] !== null, $retirement);
        }
        if (!isset($units['control'], $units['execution'])) {
            throw new \UnexpectedValueException('A deployment boot must contain both fixed unit reservations.');
        }
        $root = $rows[0];
        return new DeploymentBoot($namespace, $bootId, $root['manifest_hash'], $root['daemon_id'], $units['control'], $units['execution'], $root['retirement_requested_at'] !== null, $root['retired_at'] !== null, (bool) $root['imported_singleton']);
    }

    /** @return array{namespace:string, boot:string, unit:string, daemon:string, container:string} */
    private function parameters(DeploymentContainer $binding): array
    {
        return ['namespace' => $binding->namespace, 'boot' => $binding->bootId, 'unit' => $binding->unit->value, 'daemon' => $binding->daemonId, 'container' => $binding->containerId];
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
