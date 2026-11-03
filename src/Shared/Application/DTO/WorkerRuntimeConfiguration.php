<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Admission reservations within each independently enforced worker unit. */
#[Exclude]
final readonly class WorkerRuntimeConfiguration
{
    public int $managementReservationBytes;

    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $unit,
        public int $memoryLimitBytes,
        public int $pidsLimit,
        public int $scheduledConsoleReservationBytes,
        public string $lockDirectory,
        public string $ipcDirectory,
        public DeploymentProfile $deploymentProfile,
        public WorkerAutoscalingProfile $autoscaling = new WorkerAutoscalingProfile(),
    ) {
        $this->managementReservationBytes = $deploymentProfile->managementMemoryMiB($unit) * 1024 * 1024;
        $minimum = $autoscaling->minimumMemoryMiB($unit, $deploymentProfile) * 1024 * 1024;
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $namespace) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1
            || !in_array($unit, ['control', 'execution'], true)
            || $autoscaling->maximumConcurrency > $deploymentProfile->ordinaryConsumerLimit
            || $memoryLimitBytes < $minimum || $memoryLimitBytes > 1024 ** 4
            || $pidsLimit < $autoscaling->minimumPids($unit) || $pidsLimit > 4096
            || $scheduledConsoleReservationBytes < 0
            || $scheduledConsoleReservationBytes > $memoryLimitBytes - $minimum
            || ($scheduledConsoleReservationBytes !== 0 && ($unit !== 'execution' || $scheduledConsoleReservationBytes < $deploymentProfile->phpProcessMemoryMiB() * 1024 * 1024))
            || !$this->validPath($lockDirectory) || !$this->validPath($ipcDirectory)
        ) {
            throw new \InvalidArgumentException('Worker requires a bounded identity, absolute private directories and memory/PID capacity for every resident consumer and controller. Scheduled console requires one PHP process reservation in execution.');
        }
    }

    private function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && strlen($path) <= 4000 && !str_contains($path, "\0") && !in_array('..', explode('/', $path), true);
    }
}
