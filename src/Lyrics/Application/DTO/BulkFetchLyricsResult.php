<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

use App\Shared\Application\DTO\InlineJobRun;

/**
 * What a bulk lyrics run's walk did: the songs it queued a fetch for, and the songs without
 * lyrics it could not queue because a lookup needs an artist and a length they lack.
 */
final readonly class BulkFetchLyricsResult
{
    public function __construct(
        public int $queued,
        public int $skippedNoArtist,
        public int $skippedNoLength,
    ) {
    }

    /**
     * What an inline run of BulkFetchLyricsCommand returned.
     *
     * @throws \UnexpectedValueException when the run carries no result, which only a miswired handler causes
     */
    public static function fromRun(InlineJobRun $run): self
    {
        return $run->result instanceof self
            ? $run->result
            : throw new \UnexpectedValueException('The bulk lyrics fetch returned no result.');
    }

    /**
     * The `data` of POST /api/admin/lyrics/bulk-fetch.
     *
     * @return array{jobsEnqueued: int, skippedNoArtist: int, skippedNoLength: int}
     */
    public function toArray(): array
    {
        return [
            'jobsEnqueued' => $this->queued,
            'skippedNoArtist' => $this->skippedNoArtist,
            'skippedNoLength' => $this->skippedNoLength,
        ];
    }
}
