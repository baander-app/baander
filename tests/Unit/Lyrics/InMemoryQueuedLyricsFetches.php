<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics;

use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\DTO\BulkLyricsRunTally;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Shared\Domain\Model\Uuid;

/** Keeps the marks and the run tallies with their time to live in seconds; nothing expires. */
final class InMemoryQueuedLyricsFetches implements QueuedLyricsFetchesInterface
{
    /** @var array<string, int> song ID => TTL */
    public array $queued = [];

    /** @var array<string, int> run ID => TTL */
    public array $cancelledRuns = [];

    /** @var array<string, array{string, int}> job ID => [run ID, TTL] */
    public array $jobRuns = [];

    /**
     * @var array<string, array{ttl: int, finishedAt: ?\DateTimeImmutable, jobId: ?string, queued: int, counts: array<string, int>, songs: array<string, string>}>
     *      run ID => tally, with the outcome counted for each song
     */
    public array $runs = [];

    public ?string $latestRunId = null;

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

    public function startRun(Uuid $runId, int $ttlSeconds): void
    {
        $this->runs[$runId->toString()] = ['ttl' => $ttlSeconds, 'finishedAt' => null, 'jobId' => null, 'queued' => 0, 'counts' => [], 'songs' => []];
    }

    public function finishRun(Uuid $runId, BulkFetchLyricsResult $walk, ?string $jobId, int $ttlSeconds): void
    {
        $run = $this->runs[$runId->toString()] ?? ['counts' => [], 'songs' => []];
        $counts = $run['counts'];
        foreach ([LyricsFetchOutcome::NoArtist->value => $walk->skippedNoArtist, LyricsFetchOutcome::NoLength->value => $walk->skippedNoLength] as $cause => $skipped) {
            $counts[$cause] = ($counts[$cause] ?? 0) + $skipped;
        }

        $this->runs[$runId->toString()] = [
            'ttl' => $ttlSeconds,
            'finishedAt' => new \DateTimeImmutable(),
            'jobId' => $jobId,
            'queued' => $walk->queued,
            'counts' => $counts,
            'songs' => $run['songs'],
        ];
        $this->latestRunId = $runId->toString();
    }

    public function recordOutcome(Uuid $runId, Uuid $songId, LyricsFetchOutcome $outcome): void
    {
        $run = $runId->toString();
        $song = $songId->toString();
        if (!isset($this->runs[$run]) || isset($this->runs[$run]['songs'][$song])) {
            return;
        }

        $this->runs[$run]['songs'][$song] = $outcome->value;
        $this->runs[$run]['counts'][$outcome->value] = ($this->runs[$run]['counts'][$outcome->value] ?? 0) + 1;
    }

    public function latestRun(): ?BulkLyricsRunTally
    {
        $run = $this->latestRunId !== null ? $this->runs[$this->latestRunId] ?? null : null;
        if ($run === null || $run['finishedAt'] === null) {
            return null;
        }

        return BulkLyricsRunTally::fromCounts($run['jobId'], $run['finishedAt'], $run['queued'], $run['counts']);
    }
}
