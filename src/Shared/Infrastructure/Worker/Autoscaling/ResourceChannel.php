<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Infrastructure\Worker\DeploymentLease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Execution publishes local pressure without involving external services. */
#[Exclude]
final readonly class ResourceChannel
{
    private string $directory;

    public function __construct(string $ipcDirectory, private DeploymentLease $lease)
    {
        $this->directory = rtrim($ipcDirectory, '/') . '/' . $lease->bootId;
    }

    public function publish(ResourceObservation $observation): void
    {
        $this->validateDirectory();
        ResourceObservation::fromArray($observation->jsonSerialize());
        $raw = json_encode(['namespace' => $this->lease->namespace, 'bootId' => $this->lease->bootId,
            'epoch' => $this->lease->epoch, 'observation' => $observation], JSON_THROW_ON_ERROR);
        if (strlen($raw) > 4096) {
            throw new \RuntimeException('Resource sample exceeds its publication bound.');
        }
        $temporary = tempnam($this->directory, '.resource-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot allocate resource sample.');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $raw) !== strlen($raw)
                || !rename($temporary, $this->directory . '/resource.json')) {
                throw new \RuntimeException('Cannot publish resource sample.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(float $now): ResourceObservation
    {
        if (!is_finite($now) || $now < 0) {
            throw new \InvalidArgumentException('Resource reads require nonnegative monotonic time.');
        }
        try {
            $this->validateDirectory();
            $path = $this->directory . '/resource.json';
            clearstatcache(true, $path);
            if (is_link($path) || !is_file($path) || fileowner($path) !== fileowner('/proc/self') || (fileperms($path) & 0777) !== 0600) {
                throw new \RuntimeException('Resource sample is not private.');
            }
            $raw = file_get_contents($path, false, null, 0, 4097);
            if ($raw === false || strlen($raw) > 4096) {
                throw new \RuntimeException('Resource sample exceeds its read bound.');
            }
            $value = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
            if (!is_array($value) || count($value) !== 4 || ($value['namespace'] ?? null) !== $this->lease->namespace
                || ($value['bootId'] ?? null) !== $this->lease->bootId || ($value['epoch'] ?? null) !== $this->lease->epoch
                || !is_array($value['observation'] ?? null)) {
                throw new \RuntimeException('Foreign resource sample.');
            }
            $observation = ResourceObservation::fromArray($value['observation']);
            if ($observation->sampledAt > $now) {
                throw new \RuntimeException('Future resource sample.');
            }
            return $observation;
        } catch (\Throwable) {
            return new ResourceObservation('unavailable', $now, fault: 'cgroup_unavailable');
        }
    }

    private function validateDirectory(): void
    {
        clearstatcache(true, $this->directory);
        if (!str_starts_with($this->directory, '/') || is_link($this->directory) || realpath($this->directory) !== $this->directory
            || fileowner($this->directory) !== fileowner('/proc/self') || (fileperms($this->directory) & 0777) !== 0700) {
            throw new \RuntimeException('Resources require an existing private canonical boot directory.');
        }
    }
}
