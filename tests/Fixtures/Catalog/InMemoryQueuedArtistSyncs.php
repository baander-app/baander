<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Port\QueuedArtistSyncsInterface;
use App\Shared\Application\Port\QueuedJobWorkInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * The artist sync marks and the shared due time in memory, on a clock the test moves. Entries keep
 * their time to live in seconds and nothing expires on its own; the shared due time is the latest
 * one reserved, as the Redis store keeps it.
 */
final class InMemoryQueuedArtistSyncs implements QueuedArtistSyncsInterface, QueuedJobWorkInterface
{
    /** Milliseconds on the store's clock. */
    public int $nowMs = 0;

    /** The latest due time reserved, in milliseconds on the store's clock; null when none is pending. */
    public ?int $latestDueMs = null;

    /** @var array<string, int> artist ID => TTL */
    public array $queued = [];

    /** @var array<string, int> "artist ID:MusicBrainz ID" => TTL */
    public array $answered = [];

    /** @var array<string, int> run ID => TTL */
    public array $cancelledRuns = [];

    /** @var array<string, array{string, int}> job ID => [run ID, TTL] */
    public array $jobRuns = [];

    public function reserve(Uuid $artistId, string $musicbrainzId, int $spacingMs, int $graceSeconds): ?int
    {
        if (isset($this->queued[$artistId->toString()]) || $this->isAnswered($artistId, $musicbrainzId)) {
            return null;
        }

        return $this->reserveAgain($artistId, 0, $spacingMs, $graceSeconds);
    }

    public function reserveAgain(Uuid $artistId, int $pauseMs, int $spacingMs, int $graceSeconds): int
    {
        $afterLatest = $this->latestDueMs === null ? $this->nowMs : $this->latestDueMs + $spacingMs;
        $due = max($this->nowMs + $pauseMs, $afterLatest);
        $this->latestDueMs = $due;
        $delay = $due - $this->nowMs;
        $this->queued[$artistId->toString()] = (int) ceil($delay / 1000) + $graceSeconds;

        return $delay;
    }

    public function clearQueued(Uuid $artistId): void
    {
        unset($this->queued[$artistId->toString()]);
    }

    public function markAnswered(Uuid $artistId, string $musicbrainzId, int $ttlSeconds): void
    {
        $this->answered[self::answerKey($artistId, $musicbrainzId)] = $ttlSeconds;
    }

    public function isAnswered(Uuid $artistId, string $musicbrainzId): bool
    {
        return isset($this->answered[self::answerKey($artistId, $musicbrainzId)]);
    }

    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void
    {
        $this->cancelledRuns[$runId->toString()] = $ttlSeconds;
        $this->latestDueMs = null;
    }

    public function isRunCancelled(Uuid $runId): bool
    {
        return isset($this->cancelledRuns[$runId->toString()]);
    }

    public function recordJobRun(string $jobId, Uuid $runId, int $ttlSeconds): void
    {
        $this->jobRuns[$jobId] = [$runId->toString(), $ttlSeconds];
    }

    public function hasQueuedWork(string $jobId): bool
    {
        return isset($this->jobRuns[$jobId]);
    }

    public function cancelQueuedWork(string $jobId): bool
    {
        if (!isset($this->jobRuns[$jobId])) {
            return false;
        }
        [$runId, $ttl] = $this->jobRuns[$jobId];
        $this->markRunCancelled(Uuid::fromString($runId), $ttl);

        return true;
    }

    private static function answerKey(Uuid $artistId, string $musicbrainzId): string
    {
        return $artistId->toString() . ':' . strtolower($musicbrainzId);
    }
}
