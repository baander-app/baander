<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Service;

use App\Catalog\Application\Port\SongLyricSignature;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;

/**
 * What an LRCLIB signature lookup needs from a song: a primary artist name, and a positive
 * length, which LRCLIB matches within ±2 seconds. A single fetch and a bulk run's walk apply
 * the same rule, so the walk skips exactly the songs a fetch would not look up.
 */
final class LyricsLookupRequirements
{
    /** The cause the song cannot be looked up for, or null when it can be. */
    public static function unmetBy(SongLyricSignature $signature): ?LyricsFetchOutcome
    {
        return match (true) {
            $signature->artistName === null || trim($signature->artistName) === '' => LyricsFetchOutcome::NoArtist,
            $signature->duration === null || $signature->duration <= 0.0 => LyricsFetchOutcome::NoLength,
            default => null,
        };
    }
}
