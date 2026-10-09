<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Domain\Model\Uuid;

/**
 * Reads the lyrics stored for a song.
 *
 * Fetching from LRCLIB, searching it and applying a search result are the
 * FetchLyricsCommand, SearchLyricsQuery and ApplyLyricsCommand use cases.
 */
interface LyricsPortInterface
{
    /**
     * Get cached lyrics for a song (local DB only, no external fetch).
     */
    public function findBySongId(Uuid $songId): ?Lyrics;
}
