<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Query;

/**
 * Searches LRCLIB for lyrics by keywords.
 *
 * GET /api/lyrics/search and app:lyrics:search dispatch it; a result's ID is what
 * ApplyLyricsCommand applies.
 */
final readonly class SearchLyricsQuery
{
    public function __construct(
        public string $query,
    ) {
    }
}
