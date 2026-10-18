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
    ) {
        // Management covers a 256 MiB supervisor, a 64 MiB lease helper and 64 MiB
        // of native headroom. Execution also reserves its 3136 MiB native process envelope.
        $this->managementReservationBytes = ($unit === 'control' ? 384 : 3520) * 1024 * 1024;
        $minimum = ($unit === 'control' ? 2624 : 4160) * 1024 * 1024;
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $namespace) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1
            || !in_array($unit, ['control', 'execution'], true)
            || $memoryLimitBytes < $minimum || $memoryLimitBytes > 1024 ** 4
            || $pidsLimit < ($unit === 'control' ? 128 : 256) || $pidsLimit > 4096
            || $scheduledConsoleReservationBytes < 0
            || $scheduledConsoleReservationBytes > $memoryLimitBytes - $minimum
            || ($scheduledConsoleReservationBytes !== 0 && ($unit !== 'execution' || $scheduledConsoleReservationBytes < 320 * 1024 * 1024))
            || !$this->validPath($lockDirectory) || !$this->validPath($ipcDirectory)
        ) {
            throw new \InvalidArgumentException('Worker requires a control or execution unit, bounded identity, absolute private directories, sufficient memory (control 2624 MiB; execution 4160 MiB plus scheduled console), and PID capacity (control 128; execution 256). Scheduled console requires at least 320 MiB in execution.');
        }
    }

    private function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && strlen($path) <= 4000 && !str_contains($path, "\0") && !in_array('..', explode('/', $path), true);
    }
}
