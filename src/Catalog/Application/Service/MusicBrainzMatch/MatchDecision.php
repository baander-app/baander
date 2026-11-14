<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Whether an album may be matched without an admin: the confident candidate, or why the album needs review. */
#[Exclude]
final readonly class MatchDecision
{
    /**
     * @param int<0, 100>|null $bestScore the best candidate's score; null when there was no candidate
     */
    private function __construct(
        public ?MatchCandidate $match,
        public ?AlbumMatchReviewReason $reviewReason,
        public ?int $bestScore,
    ) {
    }

    public static function confident(MatchCandidate $match): self
    {
        return new self($match, null, $match->score);
    }

    /** @param int<0, 100>|null $bestScore */
    public static function needsReview(AlbumMatchReviewReason $reason, ?int $bestScore): self
    {
        return new self(null, $reason, $bestScore);
    }

    /** @phpstan-assert-if-true !null $this->match */
    public function isConfident(): bool
    {
        return $this->match !== null;
    }
}
