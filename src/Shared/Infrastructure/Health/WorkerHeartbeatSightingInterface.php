<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/**
 * Remembers whether this server has ever read a worker heartbeat, so a missing key
 * reads as a worker that disappeared rather than one that was never started.
 */
interface WorkerHeartbeatSightingInterface
{
    public function markSeen(): void;

    public function wasSeen(): bool;
}
