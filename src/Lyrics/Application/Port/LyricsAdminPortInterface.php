<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

/**
 * The lyrics administration reads behind GET /api/admin/lyrics/* and the
 * `app:lyrics:coverage` and `app:lyrics:status` commands. The bulk fetch starts
 * through BulkFetchLyricsCommand.
 */
interface LyricsAdminPortInterface
{
    /**
     * @return array{totalTracks: int, tracksWithLyrics: int, tracksWithoutLyrics: int, coveragePercentage: float, bySource: array<string, int>}
     */
    public function getCoverage(): array;

    /**
     * @return array{lastSyncAt: string|null, recentJobs: int, failedJobs: int, completedJobs: int}
     */
    public function getSyncStatus(): array;
}
