<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How matching one album without an admin ended: for an album that needs review, what the matcher
 * checked, and for an album to be matched again later, how much later.
 *
 * The album match queue acts on each kind when it ends its claim on the album. A request made for
 * the album meanwhile waits beside that claim, and the album then waits for a match again from
 * that request's due time instead, except after an outage:
 * - matched: removes the request it took;
 * - needs review: marks the album for review with the reason, best score, checked song count and
 *   the time of the check;
 * - retry later: gives the request back due after the outcome's delay and goes on with the next
 *   album;
 * - unavailable: gives the request back with its due time, drops a request made meanwhile, and
 *   waits 5, 15 and 60 minutes, then hourly, before it matches anything again;
 * - album gone: removes the request, as for matched.
 */
#[Exclude]
final readonly class AutomaticMatchOutcome
{
    /**
     * @param AlbumMatchReviewReason|null $reviewReason     why the album needs review; set exactly when it does
     * @param int<0, 100>|null            $bestScore        the best candidate's fit score; null without a candidate
     * @param int<0, max>|null            $checkedSongCount the album's song count the matcher checked the candidates against; set exactly when the album needs review
     * @param int<1, max>|null            $retryAfterSeconds how long the album waits before it is matched again; set exactly when it is to be retried later
     */
    private function __construct(
        public AutomaticMatchOutcomeKind $kind,
        public ?AlbumMatchReviewReason $reviewReason = null,
        public ?int $bestScore = null,
        public ?int $checkedSongCount = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function matched(): self
    {
        return new self(AutomaticMatchOutcomeKind::Matched);
    }

    /**
     * @param int<0, 100>|null $bestScore        the best candidate's fit score; null when MusicBrainz found no candidate
     * @param int<0, max>      $checkedSongCount the album's song count when the matcher read the album
     */
    public static function needsReview(AlbumMatchReviewReason $reason, ?int $bestScore, int $checkedSongCount): self
    {
        return new self(AutomaticMatchOutcomeKind::NeedsReview, $reason, $bestScore, $checkedSongCount);
    }

    /** @param int<1, max> $seconds how long this album waits before it is matched again */
    public static function retryLater(int $seconds): self
    {
        return new self(AutomaticMatchOutcomeKind::RetryLater, retryAfterSeconds: $seconds);
    }

    public static function unavailable(): self
    {
        return new self(AutomaticMatchOutcomeKind::Unavailable);
    }

    public static function albumGone(): self
    {
        return new self(AutomaticMatchOutcomeKind::AlbumGone);
    }
}
