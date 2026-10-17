<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Transcode\Application\DTO\MediaWorkerIdentity;

/** Shared fencing primitives for the two media authorities; callers hold the runtime row lock. */
trait MediaRuntimeOwnership
{
    /** @return array<string, mixed>|null */
    private function locked(string $table, string $keyColumn, string $key): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT * FROM $table WHERE $keyColumn = ? FOR UPDATE", [$key]);
        return $row === false ? null : $row;
    }

    /**
     * Read the clock in a separate statement after acquiring the row lock.
     * @param array<string, mixed> $row
     */
    private function live(array $row): bool
    {
        return $row['claim_token'] !== null && (bool) $this->connection->fetchOne(
            'SELECT CAST(? AS timestamptz) > clock_timestamp()', [$row['lease_expires_at']],
        );
    }

    /** @param array<string, mixed> $row */
    private function owns(array $row, MediaWorkerIdentity $worker, string $token): bool
    {
        return $row['claim_token'] === $token
            && $row['worker_namespace'] === $worker->namespace
            && $row['worker_boot_id'] === $worker->bootId
            && $row['worker_id'] === $worker->workerId
            && (int) $row['worker_launch_generation'] === $worker->launchGeneration
            && (int) $row['worker_lease_epoch'] === $worker->leaseEpoch
            && $this->live($row);
    }

    /** @return array<string, mixed> */
    private function owner(MediaWorkerIdentity $worker, string $token): array
    {
        return [
            'worker_namespace' => $worker->namespace,
            'worker_boot_id' => $worker->bootId,
            'worker_id' => $worker->workerId,
            'worker_launch_generation' => $worker->launchGeneration,
            'worker_lease_epoch' => $worker->leaseEpoch,
            'claim_token' => $token,
        ];
    }

    private function clearOwnerSql(): string
    {
        return 'worker_namespace = NULL, worker_boot_id = NULL, worker_id = NULL, worker_launch_generation = NULL, worker_lease_epoch = NULL, claim_token = NULL, lease_expires_at = NULL';
    }

    private function validateSeconds(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 3600) {
            throw new \InvalidArgumentException('Media lease and admission durations must be between one second and one hour.');
        }
    }
}
