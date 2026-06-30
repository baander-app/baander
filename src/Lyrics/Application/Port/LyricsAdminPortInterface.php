<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

interface LyricsAdminPortInterface
{
    /**
     * @return array{totalTracks: int, tracksWithLyrics: int, tracksWithoutLyrics: int, coveragePercentage: float, bySource: array<string, int>}
     */
    public function getCoverage(): array;

    /**
     * Enqueue bulk fetch jobs for tracks without lyrics.
     *
     * @param list<string> $trackIds Specific track IDs to fetch (empty = all without lyrics)
     * @param int|null $limit Max number of jobs to enqueue (null = unlimited)
     * @return int Number of jobs enqueued
     */
    public function triggerBulkFetch(array $trackIds = [], ?int $limit = null): int;

    /**
     * @return array{lastSyncAt: string|null, recentJobs: int, failedJobs: int, completedJobs: int}
     */
    public function getSyncStatus(): array;
}
