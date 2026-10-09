<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics;

use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Shared\Domain\Model\Uuid;

/** Keeps the marks with their time to live in seconds; nothing expires. */
final class InMemoryQueuedLyricsFetches implements QueuedLyricsFetchesInterface
{
    /** @var array<string, int> song ID => TTL */
    public array $queued = [];

    /** @var array<string, int> run ID => TTL */
    public array $cancelledRuns = [];

    /** @var array<string, array{string, int}> job ID => [run ID, TTL] */
    public array $jobRuns = [];

    public function markQueued(Uuid $songId, int $ttlSeconds): bool
    {
        if (isset($this->queued[$songId->toString()])) {
            return false;
        }
        $this->queued[$songId->toString()] = $ttlSeconds;

        return true;
    }

    public function clearQueued(Uuid $songId): void
    {
        unset($this->queued[$songId->toString()]);
    }

    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void
    {
        $this->cancelledRuns[$runId->toString()] = $ttlSeconds;
    }

    public function isRunCancelled(Uuid $runId): bool
    {
        return isset($this->cancelledRuns[$runId->toString()]);
    }

    public function recordJobRun(string $jobId, Uuid $runId, int $ttlSeconds): void
    {
        $this->jobRuns[$jobId] = [$runId->toString(), $ttlSeconds];
    }
}
