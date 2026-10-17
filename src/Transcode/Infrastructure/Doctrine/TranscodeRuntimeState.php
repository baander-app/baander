<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use Doctrine\DBAL\Connection;

final class TranscodeRuntimeState implements TranscodeExecutionOwnershipPort
{
    use MediaRuntimeOwnership;

    public function __construct(
        private readonly Connection $connection,
        private readonly TransactionPortInterface $transaction,
    ) {}

    public function lockAdmission(Uuid $videoId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Media admission requires the producer transaction.');
        }
        $this->connection->fetchOne('SELECT id FROM videos WHERE id = ? FOR UPDATE', [$videoId->toString()]);
    }

    public function lockSession(Uuid $sessionId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Media controls require the producer transaction.');
        }
        $this->connection->fetchOne('SELECT r.job_id FROM transcode_runtime_state r INNER JOIN transcode_sessions s ON s.job_id = r.job_id WHERE s.id = ? FOR UPDATE OF r', [$sessionId->toString()]);
    }

    public function admit(Uuid $jobId, Uuid $sessionId, string $requestId, DesiredTranscodeState $desired, int $deadlineSeconds = 5): TranscodeRuntimeSnapshot
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Session and execution intent must share the producer transaction.');
        }
        $this->validateSeconds($deadlineSeconds);
        $this->validateRequest($requestId);
        if (!$this->connection->fetchOne('SELECT 1 FROM transcode_sessions WHERE id = ? AND job_id = ?', [$sessionId->toString(), $jobId->toString()])) {
            throw new \LogicException('Runtime session must belong to its job.');
        }
        $row = $this->locked('transcode_runtime_state', 'job_id', $jobId->toString());
        if ($row !== null && $row['session_id'] === $sessionId->toString()) {
            return $this->snapshot($row);
        }
        if ($row !== null && !in_array($row['outcome'], ['complete', 'failed', 'cancelled', 'refused'], true)) {
            return $this->snapshot($row);
        }
        $generation = $row === null ? 1 : (int) $row['generation'] + 1;
        $revision = $row === null ? 1 : (int) $row['desired_revision'] + 1;
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO transcode_runtime_state (job_id, session_id, request_id, last_control_request_id, desired, desired_revision, observed_revision, generation, outcome, admission_deadline, updated_at)
VALUES (:job, :session, :request, NULL, CAST(:desired AS jsonb), :revision, 0, :generation, 'pending', clock_timestamp() + :deadline * interval '1 second', clock_timestamp())
ON CONFLICT (job_id) DO UPDATE SET session_id = EXCLUDED.session_id, request_id = EXCLUDED.request_id, last_control_request_id = NULL, desired = EXCLUDED.desired, desired_revision = EXCLUDED.desired_revision, observed_revision = 0, generation = EXCLUDED.generation, outcome = 'pending', admission_deadline = EXCLUDED.admission_deadline, output_directory = NULL, error = NULL, worker_namespace = NULL, worker_boot_id = NULL, worker_id = NULL, worker_launch_generation = NULL, worker_lease_epoch = NULL, claim_token = NULL, lease_expires_at = NULL, updated_at = clock_timestamp()
SQL, ['job' => $jobId->toString(), 'session' => $sessionId->toString(), 'request' => $requestId, 'desired' => $this->encodeDesired($desired), 'revision' => $revision, 'generation' => $generation, 'deadline' => $deadlineSeconds]);
        return $this->find($jobId) ?? throw new \LogicException('Admitted runtime disappeared.');
    }

    public function control(Uuid $jobId, Uuid $sessionId, string $requestId, ?string $status = null, ?AudioProfile $audioProfile = null, ?float $position = null, ?int $segmentIndex = null, bool $seek = true): TranscodeRuntimeSnapshot
    {
        $this->validateRequest($requestId);
        return $this->connection->transactional(function () use ($jobId, $sessionId, $requestId, $status, $audioProfile, $position, $segmentIndex, $seek): TranscodeRuntimeSnapshot {
            $row = $this->locked('transcode_runtime_state', 'job_id', $jobId->toString());
            if ($row === null || $row['session_id'] !== $sessionId->toString()) {
                throw new \LogicException('Control does not address the current runtime session.');
            }
            if ($row['last_control_request_id'] === $requestId || in_array($row['outcome'], ['failed', 'cancelled', 'refused'], true)) {
                return $this->snapshot($row);
            }
            $previous = $this->snapshot($row)->desired;
            $desired = new DesiredTranscodeState($audioProfile ?? $previous->audioProfile, $status ?? $previous->status, $position ?? $previous->position, $segmentIndex ?? $previous->segmentIndex, $previous->audioLanguages);
            if ($this->encodeDesired($desired) === $this->encodeDesired($previous)) {
                return $this->snapshot($row);
            }
            $positionChanged = $position !== null && $position !== $previous->position;
            $segmentChanged = $segmentIndex !== null && $segmentIndex !== $previous->segmentIndex;
            $profileChanged = $audioProfile !== null && $audioProfile->jsonSerialize() !== $previous->audioProfile->jsonSerialize();
            $restartRequired = ($seek && ($positionChanged || $segmentChanged)) || $profileChanged;
            $generation = (int) $row['generation'] + ($restartRequired ? 1 : 0);
            $cancelled = $desired->status === 'cancelled';
            $reactivated = $row['outcome'] === 'complete' && $restartRequired && !$cancelled;
            $outcome = $cancelled ? 'cancelled' : ($reactivated ? 'accepted' : $row['outcome']);
            $ownerReset = $reactivated ? ', ' . $this->clearOwnerSql() : '';
            $this->connection->executeStatement(
                'UPDATE transcode_runtime_state SET desired = CAST(:desired AS jsonb), last_control_request_id = :request, desired_revision = desired_revision + 1, generation = :generation, outcome = :outcome, output_directory = CASE WHEN generation = :generation THEN output_directory ELSE NULL END, updated_at = clock_timestamp()' . $ownerReset . ' WHERE job_id = :job',
                ['desired' => $this->encodeDesired($desired), 'request' => $requestId, 'generation' => $generation, 'outcome' => $outcome, 'job' => $jobId->toString()],
            );
            return $this->find($jobId) ?? throw new \LogicException('Controlled runtime disappeared.');
        });
    }

    public function find(Uuid $jobId): ?TranscodeRuntimeSnapshot
    {
        $row = $this->connection->fetchAssociative('SELECT *, (claim_token IS NOT NULL AND lease_expires_at > clock_timestamp()) AS ownership_live FROM transcode_runtime_state WHERE job_id = ?', [$jobId->toString()]);
        return $row === false ? null : $this->snapshot($row);
    }

    public function pending(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT *, (claim_token IS NOT NULL AND lease_expires_at > clock_timestamp()) AS ownership_live FROM transcode_runtime_state WHERE outcome IN ('pending', 'accepted', 'ready') ORDER BY updated_at, job_id LIMIT ?", [max(1, min(1000, $limit))], [\Doctrine\DBAL\ParameterType::INTEGER]);
        return array_map($this->snapshot(...), $rows);
    }

    public function claim(Uuid $jobId, MediaWorkerIdentity $worker, int $leaseSeconds = 15): ?TranscodeExecutionClaim
    {
        $this->validateSeconds($leaseSeconds);
        return $this->connection->transactional(function () use ($jobId, $worker, $leaseSeconds): ?TranscodeExecutionClaim {
            $row = $this->locked('transcode_runtime_state', 'job_id', $jobId->toString());
            if ($row === null || !in_array($row['outcome'], ['pending', 'accepted', 'ready'], true) || $this->live($row)) {
                return null;
            }
            if ($row['outcome'] === 'pending' && !$this->connection->fetchOne('SELECT CAST(? AS timestamptz) > clock_timestamp()', [$row['admission_deadline']])) {
                $this->refuse($row);
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $generation = (int) $row['generation'] + 1;
            $this->connection->executeStatement('UPDATE transcode_runtime_state SET worker_namespace = :worker_namespace, worker_boot_id = :worker_boot_id, worker_id = :worker_id, worker_launch_generation = :worker_launch_generation, worker_lease_epoch = :worker_lease_epoch, claim_token = :claim_token, lease_expires_at = clock_timestamp() + :ttl * interval \'1 second\', generation = :generation, output_directory = NULL, updated_at = clock_timestamp() WHERE job_id = :job', $this->owner($worker, $token) + ['ttl' => $leaseSeconds, 'generation' => $generation, 'job' => $jobId->toString()]);
            return new TranscodeExecutionClaim($this->find($jobId) ?? throw new \LogicException('Claimed runtime disappeared.'), $worker, $token);
        });
    }

    public function refresh(TranscodeExecutionClaim $claim): ?TranscodeExecutionClaim
    {
        return $this->connection->transactional(function () use ($claim): ?TranscodeExecutionClaim {
            $row = $this->locked('transcode_runtime_state', 'job_id', $claim->runtime->jobId->toString());
            if (!$this->sameOwner($row, $claim)) {
                return null;
            }
            return new TranscodeExecutionClaim($this->snapshot($row), $claim->worker, $claim->token);
        });
    }

    public function renew(TranscodeExecutionClaim $claim, int $leaseSeconds = 15): bool
    {
        $this->validateSeconds($leaseSeconds);
        return $this->connection->transactional(function () use ($claim, $leaseSeconds): bool {
            $row = $this->locked('transcode_runtime_state', 'job_id', $claim->runtime->jobId->toString());
            if (!$this->sameOwner($row, $claim) || in_array($row['outcome'], ['complete', 'failed', 'refused'], true)) {
                return false;
            }
            return $this->connection->executeStatement("UPDATE transcode_runtime_state SET lease_expires_at = clock_timestamp() + ? * interval '1 second', updated_at = clock_timestamp() WHERE job_id = ? AND lease_expires_at > clock_timestamp()", [$leaseSeconds, $claim->runtime->jobId->toString()]) === 1;
        });
    }

    public function observe(TranscodeExecutionClaim $claim, string $outcome, ?string $outputDirectory = null, ?string $error = null): bool
    {
        if (!in_array($outcome, ['accepted', 'ready', 'complete', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid observed execution outcome.');
        }
        if ($outputDirectory !== null && (!str_starts_with($outputDirectory, '/') || basename($outputDirectory) !== 'generation-' . $claim->runtime->generation)) {
            throw new \InvalidArgumentException('Publication directory must name the claimed generation.');
        }
        return $this->connection->transactional(function () use ($claim, $outcome, $outputDirectory, $error): bool {
            $row = $this->locked('transcode_runtime_state', 'job_id', $claim->runtime->jobId->toString());
            if (!$this->fullFence($row, $claim) || in_array($row['outcome'], ['complete', 'failed', 'refused'], true)
                || ($row['outcome'] === 'cancelled' && $outcome !== 'cancelled')) {
                return false;
            }
            $deadline = $row['outcome'] === 'pending' ? ' AND admission_deadline > clock_timestamp()' : '';
            return $this->connection->executeStatement('UPDATE transcode_runtime_state SET outcome = ?, observed_revision = desired_revision, output_directory = COALESCE(?, output_directory), error = ?, updated_at = clock_timestamp() WHERE job_id = ? AND lease_expires_at > clock_timestamp()' . $deadline, [$outcome, $outputDirectory, $error, $claim->runtime->jobId->toString()]) === 1;
        });
    }

    public function withOwnership(TranscodeExecutionClaim $claim, callable $operation): bool
    {
        $expired = new \RuntimeException('Media ownership expired during persistence.');
        try {
            return $this->transaction->run(function () use ($claim, $operation, $expired): bool {
                $row = $this->locked('transcode_runtime_state', 'job_id', $claim->runtime->jobId->toString());
                if (!$this->fullFence($row, $claim) || in_array($row['outcome'], ['complete', 'failed', 'refused'], true)) {
                    return false;
                }
                $operation();
                if (!$this->live($row)) {
                    throw $expired;
                }
                return true;
            });
        } catch (\Throwable $error) {
            if ($error === $expired) {
                return false;
            }
            throw $error;
        }
    }

    public function release(TranscodeExecutionClaim $claim): bool
    {
        return $this->connection->transactional(function () use ($claim): bool {
            $row = $this->locked('transcode_runtime_state', 'job_id', $claim->runtime->jobId->toString());
            if (!$this->fullFence($row, $claim)) {
                return false;
            }
            return $this->connection->executeStatement('UPDATE transcode_runtime_state SET ' . $this->clearOwnerSql() . ', updated_at = clock_timestamp() WHERE job_id = ? AND lease_expires_at > clock_timestamp()', [$claim->runtime->jobId->toString()]) === 1;
        });
    }

    public function expireAdmission(Uuid $jobId, Uuid $sessionId, string $requestId): bool
    {
        return $this->connection->transactional(function () use ($jobId, $sessionId, $requestId): bool {
            $row = $this->locked('transcode_runtime_state', 'job_id', $jobId->toString());
            if ($row === null || $row['session_id'] !== $sessionId->toString() || $row['request_id'] !== $requestId || $row['outcome'] !== 'pending') {
                return false;
            }
            $this->refuse($row);
            return true;
        });
    }

    /** @param array<string, mixed> $row */
    private function refuse(array $row): void
    {
        $this->connection->executeStatement("UPDATE transcode_runtime_state SET outcome = 'refused', error = 'Startup deadline expired', " . $this->clearOwnerSql() . ', updated_at = clock_timestamp() WHERE job_id = ?', [$row['job_id']]);
        $detached = $this->connection->executeStatement("UPDATE transcode_sessions SET state = 'failed', updated_at = clock_timestamp() WHERE id = ? AND state IN ('pending', 'preparing', 'active', 'paused')", [$row['session_id']]);
        if ($detached === 1) {
            $this->connection->executeStatement("UPDATE transcode_jobs SET reference_count = GREATEST(0, reference_count - 1), updated_at = clock_timestamp() WHERE id = ?", [$row['job_id']]);
        }
    }

    /** @param array<string, mixed>|null $row */
    private function sameOwner(?array $row, TranscodeExecutionClaim $claim): bool
    {
        return $row !== null && $row['session_id'] === $claim->runtime->sessionId->toString() && $this->owns($row, $claim->worker, $claim->token);
    }

    /** @param array<string, mixed>|null $row */
    private function fullFence(?array $row, TranscodeExecutionClaim $claim): bool
    {
        return $this->sameOwner($row, $claim) && (int) $row['generation'] === $claim->runtime->generation && (int) $row['desired_revision'] === $claim->runtime->desiredRevision;
    }

    private function validateRequest(string $requestId): void
    {
        if ($requestId === '' || strlen($requestId) > 128) {
            throw new \InvalidArgumentException('Media request identifier must contain at most 128 bytes.');
        }
    }

    private function encodeDesired(DesiredTranscodeState $desired): string
    {
        return json_encode(['status' => $desired->status, 'position' => $desired->position, 'segmentIndex' => $desired->segmentIndex, 'audioProfile' => $desired->audioProfile->jsonSerialize(), 'audioLanguages' => $desired->audioLanguages], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row): TranscodeRuntimeSnapshot
    {
        $desired = json_decode($row['desired'], true, 512, JSON_THROW_ON_ERROR);
        $profile = $desired['audioProfile'];
        $audioProfile = new AudioProfile($profile['name'], $profile['codec'], $profile['bitrate'], $profile['channelLayout'], $profile['channelCount'], $profile['sampleRate'], LoudnessStandard::from($profile['loudnessStandard']), $profile['downmixSurround'], $profile['applyDrc'], (float) $profile['drcRatio'], $profile['drcThreshold']);
        return new TranscodeRuntimeSnapshot(Uuid::fromString($row['job_id']), Uuid::fromString($row['session_id']), $row['request_id'], new DesiredTranscodeState($audioProfile, $desired['status'], (float) $desired['position'], (int) $desired['segmentIndex'], $desired['audioLanguages']), (int) $row['desired_revision'], (int) $row['observed_revision'], (int) $row['generation'], $row['outcome'], $row['output_directory'], $row['error'], array_key_exists('ownership_live', $row) ? (bool) $row['ownership_live'] : $this->live($row));
    }
}
