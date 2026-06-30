<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Domain\Model\Uuid;

/**
 * Port interface for lyrics operations.
 *
 * Controllers depend on this port; infrastructure implements it.
 * Orchestrates LRCLIB fetching, local caching, and search.
 */
interface LyricsPortInterface
{
    /**
     * Get cached lyrics for a song (local DB only, no external fetch).
     */
    public function findBySongId(Uuid $songId): ?Lyrics;

    /**
     * Fetch lyrics from LRCLIB for a song and store locally.
     *
     * Uses cached-first strategy: tries /api/get-cached first,
     * falls back to /api/get if no cached result.
     * Returns null if no lyrics found.
     */
    public function fetchAndStore(Uuid $songId): ?Lyrics;

    /**
     * Search LRCLIB for lyrics matching a query.
     *
     * @return LrclibSearchResult[]
     */
    public function searchLrclib(string $query): array;

    /**
     * Apply a specific LRCLIB search result to a song.
     *
     * Fetches the full lyrics record by LRCLIB ID and stores it
     * for the given song.
     */
    public function applySearchResult(int $lrclibResultId, Uuid $songId): ?Lyrics;
}
