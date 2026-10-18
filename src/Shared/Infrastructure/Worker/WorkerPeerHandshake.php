<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Bounded shared-kernel monotonic heartbeats; not a containment receipt. */
#[Exclude]
final class WorkerPeerHandshake
{
    private readonly string $directory;
    private bool $established = false;

    public function __construct(string $directory, private readonly string $namespace, private readonly string $bootId, private readonly string $unit, private readonly float $startedAt)
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (!in_array($unit, ['control', 'execution'], true) || !str_starts_with($directory, '/')) {
            throw new \InvalidArgumentException('Worker peer requires a private absolute directory and unit identity.');
        }
        $this->directory = rtrim($directory, '/') . '/' . $bootId;
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true)) {
            clearstatcache(true, $this->directory);
            if (!is_dir($this->directory)) {
                throw new \RuntimeException('Worker peer directory could not be created.');
            }
        }
        if (is_link($this->directory)
            || realpath($this->directory) !== $this->directory
            || fileowner($this->directory) !== fileowner('/proc/self') || (fileperms($this->directory) & 0777) !== 0700) {
            throw new \RuntimeException('Worker peer directory is unavailable or not canonical.');
        }
    }

    public function socketPath(): string
    {
        return $this->directory . '/native.sock';
    }

    public function publish(int $epoch, bool $ready, bool $draining, float $now): void
    {
        $path = $this->directory . '/' . $this->unit . '.json';
        $temporary = tempnam($this->directory, '.heartbeat-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot allocate worker heartbeat.');
        }
        try {
            $payload = json_encode(['namespace' => $this->namespace, 'bootId' => $this->bootId, 'unit' => $this->unit,
                'epoch' => $epoch, 'ready' => $ready, 'draining' => $draining, 'time' => $now], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $payload) !== strlen($payload) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish worker heartbeat.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Missing initial peer is permitted only inside the startup deadline. */
    public function ready(?int $epoch, float $now): bool
    {
        $peer = $this->unit === 'control' ? 'execution' : 'control';
        $path = $this->directory . '/' . $peer . '.json';
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return $this->waiting($now);
        }
        if (is_link($path) || !is_file($path) || fileowner($path) !== fileowner('/proc/self') || (fileperms($path) & 0077) !== 0 || ($raw = file_get_contents($path, false, null, 0, 2049)) === false || strlen($raw) > 2048) {
            throw new \RuntimeException('Invalid worker peer heartbeat.');
        }
        $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value) || count($value) !== 7 || ($value['namespace'] ?? null) !== $this->namespace
            || ($value['bootId'] ?? null) !== $this->bootId || ($value['unit'] ?? null) !== $peer
            || !is_int($value['epoch'] ?? null) || $value['epoch'] < 1 || ($epoch !== null && $value['epoch'] !== $epoch)
            || !is_bool($value['ready'] ?? null) || !is_bool($value['draining'] ?? null)
            || (!is_int($value['time'] ?? null) && !is_float($value['time'] ?? null)) || !is_finite((float) $value['time'])
            || $value['time'] > $now + 0.25 || $now - $value['time'] >= 2.0 || $value['draining']) {
            throw new \RuntimeException('Worker peer identity, authority or heartbeat lost.');
        }
        if (!$value['ready']) {
            return $this->waiting($now);
        }
        $this->established = true;
        return true;
    }

    private function waiting(float $now): bool
    {
        if ($this->established || $now >= $this->startedAt + 30.0) {
            throw new \RuntimeException('Worker peer startup or continuity deadline exceeded.');
        }
        return false;
    }
}
