<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Command to fetch and store lyrics for a single song from LRCLIB.
 */
final readonly class FetchLyricsCommand
{
    public function __construct(
        private Uuid $songId,
    ) {
    }

    public function getSongId(): Uuid
    {
        return $this->songId;
    }
}
