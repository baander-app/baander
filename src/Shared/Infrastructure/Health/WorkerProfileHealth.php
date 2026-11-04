<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\DTO\DeploymentProfile;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Whether web runs the deployment profile the active worker boot was started with.
 *
 * A worker refuses a profile that disagrees with its own manifest, but web and workers read
 * their profiles separately, so a source install can start web with other slots than the
 * workers. The active boot is the one holding a namespace's deployment lease; its row records
 * the fingerprint of the profile it started with. A different fingerprint reads unhealthy and
 * alerts administrators; like the worker heartbeat it does not decide the web container's own
 * health. Without an active boot, or when the boot predates profile recording, the check reads
 * not available. An unreachable database is left to the PostgreSQL check.
 */
final readonly class WorkerProfileHealth
{
    public const string COMPONENT = 'worker_profile';

    public function __construct(
        private Connection $connection,
        private DeploymentProfile $profile,
    ) {
    }

    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $boots = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT b.namespace, b.boot_id, b.deployment_profile_fingerprint
                FROM worker_deployment_leases l
                JOIN worker_deployment_boots b ON b.namespace = l.namespace AND b.boot_id = l.owner_boot_id
                WHERE l.state = 'active'
                ORDER BY b.namespace
                SQL);
        } catch (Throwable $e) {
            return $this->result(HealthStatus::NotAvailable, $started, ['reason' => 'Worker boots unreadable', 'error' => $e->getMessage()]);
        }
        $fingerprint = $this->profile->fingerprint();
        $details = ['webProfile' => $fingerprint];
        if ($boots === []) {
            return $this->result(HealthStatus::NotAvailable, $started, $details + ['reason' => 'No active worker boot']);
        }
        $mismatched = [];
        $unknown = [];
        foreach ($boots as $boot) {
            $recorded = $boot['deployment_profile_fingerprint'];
            if (!is_string($recorded)) {
                $unknown[] = $boot['namespace'] . '/' . $boot['boot_id'];
            } elseif (!hash_equals($fingerprint, $recorded)) {
                $mismatched[] = ['namespace' => $boot['namespace'], 'bootId' => $boot['boot_id'], 'workerProfile' => $recorded];
            }
        }
        if ($mismatched !== []) {
            return $this->result(HealthStatus::Unhealthy, $started, $details + [
                'reason' => 'Web and the active worker boot run different deployment profiles',
                'mismatched' => $mismatched,
            ]);
        }
        if ($unknown !== []) {
            return $this->result(HealthStatus::NotAvailable, $started, $details + [
                'reason' => 'The active worker boot did not record its deployment profile',
                'unrecorded' => $unknown,
            ]);
        }
        return $this->result(HealthStatus::Healthy, $started, $details + ['boots' => count($boots)]);
    }

    /** @param array<string, mixed> $details */
    private function result(HealthStatus $status, float $started, array $details): HealthCheckResult
    {
        return new HealthCheckResult(self::COMPONENT, $status, (microtime(true) - $started) * 1000, $details);
    }
}
