<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Stores the lyrics of one LRCLIB search result for a song that has no lyrics yet.
 *
 * POST /api/lyrics/search/{resultId}/apply and app:lyrics:apply dispatch it.
 */
final readonly class ApplyLyricsCommand
{
    public function __construct(
        public int $lrclibResultId,
        public Uuid $songId,
    ) {
    }
}
