<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * An album's place in automatic MusicBrainz matching: a match requested by an import or an admin,
 * due from a given time, or the review the matcher left for an admin with what it checked.
 * An album with nothing pending has no match state.
 */
final readonly class AlbumMatchState
{
    /**
     * @param ?int $checkedSongCount the album's song count when the matcher checked it
     * @param ?int $bestScore        the best candidate's fit score, from 0 to 100
     */
    public function __construct(
        public Uuid $albumId,
        public AlbumMatchStatus $status,
        public AlbumMatchSource $source,
        public DateTimeImmutable $dueAt,
        public ?AlbumMatchReviewReason $reason = null,
        public ?int $checkedSongCount = null,
        public ?DateTimeImmutable $checkedAt = null,
        public ?int $bestScore = null,
    ) {
    }
}
