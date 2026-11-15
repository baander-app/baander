<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use App\Shared\Domain\Model\Uuid;

/**
 * Command to fetch and store lyrics for a single song from LRCLIB.
 *
 * A fetch that a bulk run queued names the run, so the fetch is skipped when the run was
 * cancelled and releases the song's queued mark when it is handled.
 *
 * A fetch a worker handles from the queue, for a bulk run or after an import, says that it
 * was queued: nobody waits on its answer, so its request may wait longer for LRCLIB than
 * that of a fetch someone started for one song.
 */
final readonly class FetchLyricsCommand
{
    public function __construct(
        private Uuid $songId,
        private ?Uuid $bulkRunId = null,
        private bool $queued = false,
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

    public function isQueued(): bool
    {
        return $this->queued;
    }
}
