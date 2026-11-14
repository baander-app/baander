<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;

/**
 * Decides whether the best of an album's scored releases may be applied without an admin.
 *
 * The best release first takes in its twins: editions scoring less than the lead below it that hold
 * the same tracks in the same order with the same titles and artist credits and lengths within the
 * pairing tolerance of each other, whatever their recording IDs and however their discs split the
 * tracks. The candidate is confident when
 * it has the album's track count, pairs every local track within the tolerance, scores at least
 * 90 and leads the best remaining release by at least 10. Otherwise the album needs review for the
 * first of those checks that fails; an album without releases needs review for having none.
 */
final class MatchConfidence
{
    public const int MINIMUM_SCORE = 90;
    public const int MINIMUM_LEAD = 10;

    /** @param list<ScoredRelease> $releases the album's looked-up releases, in any order */
    public function decide(array $releases): MatchDecision
    {
        usort($releases, self::better(...));
        $best = array_first($releases);
        if ($best === null) {
            return MatchDecision::needsReview(AlbumMatchReviewReason::NoCandidates, null);
        }

        $isTwin = static fn (ScoredRelease $scored): bool => $scored === $best
            || ($best->score - $scored->score < self::MINIMUM_LEAD && self::areTwins($best->release, $scored->release));
        $candidate = array_filter($releases, $isTwin) |> array_values(...) |> self::collapse(...);
        $runnerUp = array_find($releases, static fn (ScoredRelease $scored): bool => !$isTwin($scored));
        $reason = self::reviewReason($candidate, $runnerUp);

        return $reason === null
            ? MatchDecision::confident($candidate)
            : MatchDecision::needsReview($reason, $candidate->score);
    }

    private static function reviewReason(MatchCandidate $candidate, ?ScoredRelease $runnerUp): ?AlbumMatchReviewReason
    {
        $pairs = $candidate->pairs;

        return match (true) {
            $candidate->release->trackCount() !== count($pairs->pairs) + count($pairs->unpairedLocal) => AlbumMatchReviewReason::TrackCountDiffers,
            $pairs->unpairedLocal !== [] || !array_all($pairs->pairs, static fn (TrackPair $pair): bool => $pair->isWithinLengthTolerance()) => AlbumMatchReviewReason::TrackLengthsDiffer,
            $candidate->score < self::MINIMUM_SCORE => AlbumMatchReviewReason::LowScore,
            $runnerUp !== null && $candidate->score - $runnerUp->score < self::MINIMUM_LEAD => AlbumMatchReviewReason::CloseRunnerUp,
            default => null,
        };
    }

    /**
     * One candidate for the best release and its twins. Twins may split the same tracks into discs
     * differently, and a match writes the disc and number of the track each song is paired with, so
     * the candidate stands for the twin that keeps the most songs at their own disc and number, the
     * earliest released of those, and pairs the songs as that twin does.
     *
     * @param non-empty-list<ScoredRelease> $twins
     */
    private static function collapse(array $twins): MatchCandidate
    {
        usort($twins, static fn (ScoredRelease $one, ScoredRelease $other): int => self::earlier($one->release, $other->release));
        $mostInPlace = array_map(self::songsInPlace(...), $twins) |> max(...);
        $recorded = array_find($twins, static fn (ScoredRelease $twin): bool => self::songsInPlace($twin) === $mostInPlace) ?? $twins[0];

        return new MatchCandidate(
            release: $recorded->release,
            score: array_column($twins, 'score') |> max(...),
            pairs: $recorded->pairs,
            editions: array_column($twins, 'release'),
        );
    }

    /** How many songs the release pairs with the track at their own disc and number; a song without a disc counts as on the first. */
    private static function songsInPlace(ScoredRelease $scored): int
    {
        return array_filter(
            $scored->pairs->pairs,
            static fn (TrackPair $pair): bool => ($pair->local->disc ?? 1) === $pair->release->disc && $pair->local->position === $pair->release->track->position,
        ) |> count(...);
    }

    private static function areTwins(MusicBrainzRelease $one, MusicBrainzRelease $other): bool
    {
        $otherTracks = $other->tracks();

        return count($one->tracks()) === count($otherTracks)
            && array_all($one->tracks(), static fn (MusicBrainzTrack $track, int $index): bool => self::twinTracks($track, $otherTracks[$index]));
    }

    private static function twinTracks(MusicBrainzTrack $one, MusicBrainzTrack $other): bool
    {
        return $one->title === $other->title
            && self::creditKey($one->artistCredit) === self::creditKey($other->artistCredit)
            && self::lengthsAgree($one->lengthMs, $other->lengthMs);
    }

    /** @return list<array{string, string, string}> */
    private static function creditKey(MusicBrainzArtistCredit $credit): array
    {
        return array_map(
            static fn (MusicBrainzCreditedArtist $artist): array => [$artist->artistId, $artist->name, $artist->joinPhrase],
            $credit->artists,
        );
    }

    private static function lengthsAgree(?int $oneMs, ?int $otherMs): bool
    {
        if ($oneMs === null || $otherMs === null) {
            return $oneMs === $otherMs;
        }

        return abs($oneMs - $otherMs) <= TrackPairing::LENGTH_TOLERANCE_SECONDS * 1000;
    }

    /** Higher score first, then the earlier release. */
    private static function better(ScoredRelease $one, ScoredRelease $other): int
    {
        return $other->score <=> $one->score ?: self::earlier($one->release, $other->release);
    }

    /**
     * Earlier release date first, a partial date counting as the start of the period it names and
     * an unknown date last, then lower release ID.
     */
    private static function earlier(MusicBrainzRelease $one, MusicBrainzRelease $other): int
    {
        return [$one->date === null, $one->date, $one->id] <=> [$other->date === null, $other->date, $other->id];
    }
}
