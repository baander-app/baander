<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use JsonException;
use RuntimeException;
use ValueError;

/** Atomically publishes the last worker observation; its age does not establish worker liveness. */
final readonly class WorkerEncoderCapabilitiesSnapshotStore
{
    public function __construct(private string $snapshotPath)
    {
    }

    public function publish(EncoderProfile $profile, float $bitrateMultiplier, string $workerIdentity): void
    {
        $directory = dirname($this->snapshotPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create worker capabilities snapshot directory');
        }
        $temporary = $this->snapshotPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode([
            'version' => 1,
            'observedAt' => time(),
            'workerIdentity' => $workerIdentity,
            'profile' => $profile->jsonSerialize(),
            'bitrateMultiplier' => $bitrateMultiplier,
        ], JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->snapshotPath)) {
                throw new RuntimeException('Cannot publish worker capabilities snapshot');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(): ?WorkerEncoderCapabilitiesSnapshot
    {
        if (!is_file($this->snapshotPath)) {
            return null;
        }
        $json = @file_get_contents($this->snapshotPath);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['version'] ?? null) !== 1
                || !is_int($data['observedAt'] ?? null)
                || $data['observedAt'] > time()
                || !is_string($data['workerIdentity'] ?? null) || $data['workerIdentity'] === ''
                || !is_numeric($data['bitrateMultiplier'] ?? null) || (float) $data['bitrateMultiplier'] <= 0
                || !is_array($data['profile'] ?? null)) {
                return null;
            }
            foreach (['accelerator', 'encoder', 'decoder', 'hwaccelMethod', 'hwaccelDevice', 'hwaccelOutputFormat'] as $field) {
                if (!is_string($data['profile'][$field] ?? null)) {
                    return null;
                }
            }
            if ($data['profile']['encoder'] === '') {
                return null;
            }

            return new WorkerEncoderCapabilitiesSnapshot(
                EncoderProfile::fromArray($data['profile']),
                (float) $data['bitrateMultiplier'],
                $data['workerIdentity'],
                $data['observedAt'],
            );
        } catch (JsonException|ValueError) {
            return null;
        }
    }
}
