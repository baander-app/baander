<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use Closure;

/**
 * Scores from 0 to 100 how well a MusicBrainz candidate fits a library album, song or artist.
 * MusicBrainz's own search score ranks text alone and cannot tell editions of an album apart; this
 * score adds track counts and lengths.
 *
 * Titles and names are compared after folding case, punctuation (typographic apostrophes
 * included) and featuring joiners, so "Post Malone, Luke Combs" equals "Post Malone feat. Luke
 * Combs"; titles also drop a featured-artist suffix such as "(feat. Luke Combs)". Folded text that
 * is equal fits fully; other text fits by its edit distance, at most 90 percent. Each score is the
 * weighted sum of its fits:
 *
 * - a release search hit: title 40, artist 30, track count 30;
 * - a looked-up release: title 25, artist 20, track count 20, track lengths 35;
 * - a recording: title 45, artist 35, length 20;
 * - an artist: name alone, or name 80 and disambiguation 20 when the library artist has one.
 *
 * A track count fits fully when equal and at most half otherwise. A length fits fully within the
 * pairing tolerance, not at all from 30 seconds off, and half when either length is unknown. A
 * release's track lengths fit by the sum of its pairs' length fits over the larger track count.
 */
final readonly class CandidateScorer
{
    private const float NEAR_MATCH_CEILING = 0.9;
    private const float UNKNOWN_LENGTH_FIT = 0.5;
    private const float NO_LENGTH_FIT_SECONDS = 30.0;

    private const float SUMMARY_TITLE_WEIGHT = 40.0;
    private const float SUMMARY_ARTIST_WEIGHT = 30.0;
    private const float SUMMARY_TRACK_COUNT_WEIGHT = 30.0;

    private const float RELEASE_TITLE_WEIGHT = 25.0;
    private const float RELEASE_ARTIST_WEIGHT = 20.0;
    private const float RELEASE_TRACK_COUNT_WEIGHT = 20.0;
    private const float RELEASE_LENGTHS_WEIGHT = 35.0;

    private const float RECORDING_TITLE_WEIGHT = 45.0;
    private const float RECORDING_ARTIST_WEIGHT = 35.0;
    private const float RECORDING_LENGTH_WEIGHT = 20.0;

    private const float ARTIST_NAME_WEIGHT = 80.0;
    private const float ARTIST_DISAMBIGUATION_WEIGHT = 20.0;

    /**
     * Applied in turn to lower-cased text: apostrophes go, other punctuation becomes a space, and
     * featuring joiners go as whole words.
     */
    private const array NAME_FOLDS = [
        "/['’‘`´]/u" => '',
        '/[^\p{L}\p{N}]+/u' => ' ',
        '/(?<=^| )(?:feat|ft|featuring)(?= |$)/u' => '',
        '/ {2,}/' => ' ',
    ];

    /** A featured-artist suffix of a title: bracketed, or running to the end. */
    private const string FEATURED_SUFFIX = '/\s*(?:[(\[]\s*(?:feat|ft|featuring)\b[^)\]]*[)\]]|\s(?:feat|ft|featuring)\b.*$)/iu';

    public function __construct(
        private TrackPairing $pairing,
    ) {
    }

    /**
     * Ranks a release search hit, which has no tracks yet, by its title, artist and track count,
     * to choose which hits are worth looking up.
     *
     * @return int<0, 100>
     */
    public function scoreReleaseSummary(MusicBrainzReleaseSummary $summary, LocalAlbum $album): int
    {
        return self::percent(
            self::SUMMARY_TITLE_WEIGHT * self::titleFit($album->title, $summary->title)
            + self::SUMMARY_ARTIST_WEIGHT * self::creditFit([$album->artistName], $summary->artistCredit)
            + self::SUMMARY_TRACK_COUNT_WEIGHT * self::trackCountFit($album->trackCount(), $summary->trackCount),
        );
    }

    /** Scores a looked-up release by its title, artist, track count and the lengths of its paired tracks. */
    public function scoreRelease(MusicBrainzRelease $release, LocalAlbum $album): ScoredRelease
    {
        $pairs = $this->pairing->pair($album->tracks, $release);
        $lengthsFit = array_map(static fn (TrackPair $pair): float => self::lengthFit($pair->lengthDifferenceSeconds()), $pairs->pairs)
            |> array_sum(...);

        return new ScoredRelease($release, self::percent(
            self::RELEASE_TITLE_WEIGHT * self::titleFit($album->title, $release->title)
            + self::RELEASE_ARTIST_WEIGHT * self::creditFit([$album->artistName], $release->artistCredit)
            + self::RELEASE_TRACK_COUNT_WEIGHT * self::trackCountFit($album->trackCount(), $release->trackCount())
            + self::RELEASE_LENGTHS_WEIGHT * $lengthsFit / max($album->trackCount(), $release->trackCount(), 1),
        ), $pairs);
    }

    /** @return int<0, 100> */
    public function scoreRecording(MusicBrainzRecording $recording, LocalSong $song): int
    {
        $difference = $song->lengthSeconds === null || $recording->lengthMs === null
            ? null
            : abs($song->lengthSeconds - $recording->lengthMs / 1000);

        return self::percent(
            self::RECORDING_TITLE_WEIGHT * self::titleFit($song->title, $recording->title)
            + self::RECORDING_ARTIST_WEIGHT * self::creditFit($song->artistNames, $recording->artistCredit)
            + self::RECORDING_LENGTH_WEIGHT * self::lengthFit($difference),
        );
    }

    /** @return int<0, 100> */
    public function scoreArtist(MusicBrainzArtist $candidate, LocalArtist $artist): int
    {
        $nameFit = self::similarity($artist->name, $candidate->name, self::foldName(...));
        if ($artist->disambiguation === null || trim($artist->disambiguation) === '') {
            return self::percent(100 * $nameFit);
        }

        return self::percent(
            self::ARTIST_NAME_WEIGHT * $nameFit
            + self::ARTIST_DISAMBIGUATION_WEIGHT * self::similarity($artist->disambiguation, $candidate->disambiguation ?? '', self::foldName(...)),
        );
    }

    /**
     * The better of how the whole credit fits all local names, and how its first artist, by
     * credited or own name, fits the first local name: a tag often names the primary artist alone.
     *
     * @param list<string> $names
     */
    private static function creditFit(array $names, MusicBrainzArtistCredit $credit): float
    {
        $whole = self::similarity(implode(' ', $names), $credit->text(), self::foldName(...));
        $first = array_first($credit->artists);
        if ($first === null || $names === []) {
            return $whole;
        }

        return max(
            $whole,
            self::similarity($names[0], $first->name, self::foldName(...)),
            self::similarity($names[0], $first->artistName, self::foldName(...)),
        );
    }

    private static function titleFit(string $local, string $candidate): float
    {
        return self::similarity($local, $candidate, self::foldTitle(...));
    }

    /** 1 for an equal count, else at most a half, shrinking as the counts part. */
    private static function trackCountFit(int $local, int $candidate): float
    {
        if ($local === $candidate) {
            return 1.0;
        }

        return 0.5 * min($local, $candidate) / max($local, $candidate);
    }

    /** 1 within the pairing tolerance, falling to 0 at 30 seconds off; a half when unknown. */
    private static function lengthFit(?float $differenceSeconds): float
    {
        if ($differenceSeconds === null) {
            return self::UNKNOWN_LENGTH_FIT;
        }
        if ($differenceSeconds <= TrackPairing::LENGTH_TOLERANCE_SECONDS) {
            return 1.0;
        }

        return max(0.0, (self::NO_LENGTH_FIT_SECONDS - $differenceSeconds) / (self::NO_LENGTH_FIT_SECONDS - TrackPairing::LENGTH_TOLERANCE_SECONDS));
    }

    /**
     * 1 when the folded texts are equal, else their edit-distance similarity scaled to at most
     * 0.9. Text that folds to nothing, such as "!!!", fits only when equal ignoring case.
     *
     * @param Closure(string): string $fold
     */
    private static function similarity(string $local, string $candidate, Closure $fold): float
    {
        $one = $fold($local);
        $other = $fold($candidate);
        if ($one === '' || $other === '') {
            $plain = $local |> trim(...) |> mb_strtolower(...);

            return $plain !== '' && $plain === ($candidate |> trim(...) |> mb_strtolower(...)) ? 1.0 : 0.0;
        }
        if ($one === $other) {
            return 1.0;
        }

        return self::NEAR_MATCH_CEILING * (1 - levenshtein($one, $other) / max(strlen($one), strlen($other)));
    }

    private static function foldTitle(string $title): string
    {
        return (preg_replace(self::FEATURED_SUFFIX, '', $title) ?? $title)
            |> self::foldName(...);
    }

    private static function foldName(string $name): string
    {
        return (preg_replace(array_keys(self::NAME_FOLDS), self::NAME_FOLDS, mb_strtolower($name)) ?? '')
            |> trim(...);
    }

    /** @return int<0, 100> */
    private static function percent(float $score): int
    {
        return max(0, min(100, (int) round($score)));
    }
}
