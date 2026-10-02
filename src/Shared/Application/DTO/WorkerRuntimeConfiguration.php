<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Explicit admission reservations, not measurements or resource enforcement. */
#[Exclude]
final readonly class WorkerRuntimeConfiguration
{
    public function __construct(
        public string $namespace,
        public string $bootId,
        public int $memoryLimitBytes,
        public int $managementReservationBytes,
        public int $consumerReservationBytes,
        public int $relayReservationBytes,
        public string $lockDirectory,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $namespace) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1
            || $memoryLimitBytes < 1 || $memoryLimitBytes > 1024 * 1024 * 1024 * 1024
            || $managementReservationBytes < 128 * 1024 * 1024
            || $consumerReservationBytes < 320 * 1024 * 1024 || $relayReservationBytes < 320 * 1024 * 1024
            || $managementReservationBytes > $memoryLimitBytes
            || $consumerReservationBytes > $memoryLimitBytes - $managementReservationBytes
            || $relayReservationBytes > $memoryLimitBytes - $managementReservationBytes - $consumerReservationBytes
            || !str_starts_with($lockDirectory, '/') || strlen($lockDirectory) > 4096 || str_contains($lockDirectory, "\0")
        ) {
            throw new InvalidArgumentException('Worker runtime requires bounded identity, an absolute lock directory, management reservation at least 128 MiB, and role reservations at least 320 MiB fitting its memory ceiling.');
        }
    }
}
