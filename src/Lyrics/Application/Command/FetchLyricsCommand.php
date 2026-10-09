<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Command to fetch and store lyrics for a single song from LRCLIB.
 *
 * A fetch that a bulk run queued names the run, so the fetch is skipped when the run was
 * cancelled and releases the song's queued mark when it is handled.
 */
final readonly class FetchLyricsCommand
{
    public function __construct(
        private Uuid $songId,
        private ?Uuid $bulkRunId = null,
    ) {
    }

    public function getSongId(): Uuid
    {
        return $this->songId;
    }

    public function getBulkRunId(): ?Uuid
    {
        return $this->bulkRunId;
    }
}
