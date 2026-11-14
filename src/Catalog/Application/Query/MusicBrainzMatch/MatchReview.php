<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;

/** An album the matcher could not match confidently, with what it found when it checked. */
final readonly class MatchReview
{
    /**
     * @param ?int $bestScore the best candidate's fit score, from 0 to 100; null when MusicBrainz returned no candidate
     */
    public function __construct(
        public string $albumPublicId,
        public string $title,
        public AlbumMatchReviewReason $reason,
        public \DateTimeImmutable $checkedAt,
        public ?int $bestScore,
    ) {
    }

    /**
     * One item of the review list, as the admin API and `app:metadata:match:reviews --json` return it.
     *
     * @return array{albumPublicId: string, title: string, reason: string, checkedAt: string, bestScore: ?int}
     */
    public function toArray(): array
    {
        return [
            'albumPublicId' => $this->albumPublicId,
            'title' => $this->title,
            'reason' => $this->reason->value,
            'checkedAt' => $this->checkedAt->format(\DateTimeInterface::ATOM),
            'bestScore' => $this->bestScore,
        ];
    }
}
