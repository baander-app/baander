<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use Doctrine\DBAL\Connection;

final class AudioRenditionRuntime implements AudioRenditionRuntimePort
{
    use MediaRuntimeOwnership;

    public function __construct(private readonly Connection $connection) {}

    public function admit(AudioRenditionInput $input): AudioRenditionRuntimeSnapshot
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO audio_rendition_runtime (rendition_key, track_key, source_signature, source_path, format, bitrate, output_directory, state, generation, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, clock_timestamp()) ON CONFLICT DO NOTHING
SQL, [$input->key(), $input->trackKey, $input->sourceSignature, $input->sourcePath, $input->format->value, $input->bitrate, $input->outputDirectory]);
        $runtime = $this->find($input->key()) ?? throw new \LogicException('Audio rendition admission disappeared.');
        if ($runtime->input->sourcePath !== $input->sourcePath || $runtime->input->outputDirectory !== $input->outputDirectory) {
            throw new \LogicException('An admitted audio rendition has an immutable input and output root.');
        }
        return $runtime;
    }

    public function find(string $key): ?AudioRenditionRuntimeSnapshot
    {
        $row = $this->connection->fetchAssociative('SELECT *, (claim_token IS NOT NULL AND lease_expires_at > clock_timestamp()) AS ownership_live FROM audio_rendition_runtime WHERE rendition_key = ?', [$key]);
        return $row === false ? null : $this->snapshot($row);
    }

    /** @return list<AudioRenditionRuntimeSnapshot> */
    public function pending(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT *, (claim_token IS NOT NULL AND lease_expires_at > clock_timestamp()) AS ownership_live FROM audio_rendition_runtime WHERE (state = 'pending' AND (claim_token IS NULL OR lease_expires_at <= clock_timestamp())) OR (state = 'running' AND lease_expires_at <= clock_timestamp()) ORDER BY updated_at, rendition_key LIMIT ?", [max(1, min(1000, $limit))], [\Doctrine\DBAL\ParameterType::INTEGER]);
        return array_map($this->snapshot(...), $rows);
    }

    public function claim(string $key, MediaWorkerIdentity $worker, int $leaseSeconds = 15): ?AudioRenditionClaim
    {
        $this->validateSeconds($leaseSeconds);
        return $this->connection->transactional(function () use ($key, $worker, $leaseSeconds): ?AudioRenditionClaim {
            $row = $this->locked('audio_rendition_runtime', 'rendition_key', $key);
            if ($row === null || !in_array($row['state'], ['pending', 'running'], true) || $this->live($row)) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $this->connection->executeStatement('UPDATE audio_rendition_runtime SET worker_namespace = :worker_namespace, worker_boot_id = :worker_boot_id, worker_id = :worker_id, worker_launch_generation = :worker_launch_generation, worker_lease_epoch = :worker_lease_epoch, claim_token = :claim_token, lease_expires_at = clock_timestamp() + :ttl * interval \'1 second\', generation = generation + 1, state = \'pending\', partial_path = NULL, final_path = NULL, error = NULL, updated_at = clock_timestamp() WHERE rendition_key = :key', $this->owner($worker, $token) + ['ttl' => $leaseSeconds, 'key' => $key]);
            return new AudioRenditionClaim($this->find($key) ?? throw new \LogicException('Claimed rendition disappeared.'), $worker, $token);
        });
    }

    public function renew(AudioRenditionClaim $claim, int $leaseSeconds = 15): bool
    {
        $this->validateSeconds($leaseSeconds);
        return $this->mutate($claim, "lease_expires_at = clock_timestamp() + :ttl * interval '1 second'", ['ttl' => $leaseSeconds]);
    }

    public function start(AudioRenditionClaim $claim, string $partialPath, string $finalPath): bool
    {
        $directory = $claim->runtime->generationDirectory();
        foreach ([$partialPath, $finalPath] as $path) {
            if (dirname($path) !== $directory || basename($path) === '.' || basename($path) === '..') {
                throw new \InvalidArgumentException('Audio publication paths must be direct children of the claimed generation directory.');
            }
        }
        return $this->mutate($claim, "state = 'running', partial_path = :partial, final_path = :final", ['partial' => $partialPath, 'final' => $finalPath]);
    }

    public function withOwnership(AudioRenditionClaim $claim, callable $operation): bool
    {
        $lost = new \RuntimeException('Audio rendition ownership changed during publication.');
        try {
            return $this->connection->transactional(function () use ($claim, $operation, $lost): bool {
                $row = $this->locked('audio_rendition_runtime', 'rendition_key', $claim->runtime->key);
                if (!$this->ownsClaim($row, $claim) || !in_array($row['state'], ['pending', 'running'], true)) {
                    return false;
                }
                $operation();
                $published = $this->locked('audio_rendition_runtime', 'rendition_key', $claim->runtime->key);
                if (!$this->ownsClaim($published, $claim)) {
                    throw $lost;
                }
                return true;
            });
        } catch (\Throwable $error) {
            if ($error === $lost) {
                return false;
            }
            throw $error;
        }
    }

    public function complete(AudioRenditionClaim $claim): bool
    {
        return $this->mutate($claim, "state = 'complete'", [], true);
    }

    public function resetCompleted(string $key, int $generation): bool
    {
        return $this->connection->transactional(function () use ($key, $generation): bool {
            $row = $this->locked('audio_rendition_runtime', 'rendition_key', $key);
            if ($row === null || $row['state'] !== 'complete' || (int) $row['generation'] !== $generation) {
                return false;
            }
            return $this->connection->executeStatement("UPDATE audio_rendition_runtime SET state = 'pending', partial_path = NULL, final_path = NULL, error = NULL, " . $this->clearOwnerSql() . ', updated_at = clock_timestamp() WHERE rendition_key = ?', [$key]) === 1;
        });
    }

    public function fail(AudioRenditionClaim $claim, string $error): bool
    {
        return $this->mutate($claim, "state = 'failed', error = :error", ['error' => $error]);
    }

    public function defer(AudioRenditionClaim $claim): bool
    {
        return $this->mutate($claim, "state = 'pending', partial_path = NULL, final_path = NULL, " . $this->clearOwnerSql());
    }

    /** @param array<string, mixed> $parameters */
    private function mutate(AudioRenditionClaim $claim, string $assignment, array $parameters = [], bool $requiresRunning = false): bool
    {
        return $this->connection->transactional(function () use ($claim, $assignment, $parameters, $requiresRunning): bool {
            $row = $this->locked('audio_rendition_runtime', 'rendition_key', $claim->runtime->key);
            if (!$this->ownsClaim($row, $claim)
                || !in_array($row['state'], ['pending', 'running'], true)
                || ($requiresRunning && ($row['state'] !== 'running' || $row['final_path'] === null))
            ) {
                return false;
            }
            return $this->connection->executeStatement('UPDATE audio_rendition_runtime SET ' . $assignment . ', updated_at = clock_timestamp() WHERE rendition_key = :key AND lease_expires_at > clock_timestamp()', $parameters + ['key' => $claim->runtime->key]) === 1;
        });
    }

    /** @param array<string, mixed>|null $row */
    private function ownsClaim(?array $row, AudioRenditionClaim $claim): bool
    {
        return $row !== null
            && (int) $row['generation'] === $claim->runtime->generation
            && $this->owns($row, $claim->worker, $claim->token);
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row): AudioRenditionRuntimeSnapshot
    {
        return new AudioRenditionRuntimeSnapshot($row['rendition_key'], new AudioRenditionInput($row['track_key'], $row['source_signature'], $row['source_path'], AudioRenditionFormat::from($row['format']), (int) $row['bitrate'], $row['output_directory']), $row['state'], (int) $row['generation'], $row['partial_path'], $row['final_path'], $row['error'], array_key_exists('ownership_live', $row) ? (bool) $row['ownership_live'] : $this->live($row));
    }
}
