<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;

/**
 * Pairs the tracks of a local album with the tracks of a release, each track at most once:
 *
 * 1. By disc and position: a local track pairs with the release track at its tagged disc (the
 *    first disc when it has no disc tag) and track number, whatever their lengths. Two local
 *    tracks tagged with the same disc and number take this step for neither.
 * 2. By length, among the tracks still free: a local track pairs with the release track whose
 *    length is within the tolerance of its own when each is the only such track for the other.
 *    A length that fits several tracks is never guessed at.
 *
 * What remains on either side is left unpaired.
 */
final class TrackPairing
{
    /** How far a local file's length may differ from a release track's for the two to pair by length. */
    public const float LENGTH_TOLERANCE_SECONDS = 3.0;

    /** @param list<LocalTrack> $localTracks */
    public function pair(array $localTracks, MusicBrainzRelease $release): TrackPairs
    {
        $releaseTracks = self::releaseTracks($release);
        $byPosition = array_column(
            array_map(static fn (ReleaseTrack $track): array => [self::key($track->disc, $track->track->position), $track], $releaseTracks),
            1,
            0,
        );
        $taggedCounts = array_map(self::localKey(...), $localTracks) |> array_filter(...) |> array_count_values(...);

        /** @var array<string, TrackPair> $pairs by release key */
        $pairs = [];
        $freeLocal = [];
        foreach ($localTracks as $local) {
            $key = self::localKey($local);
            if ($key !== null && ($taggedCounts[$key] ?? 0) === 1 && isset($byPosition[$key])) {
                $pairs[$key] = new TrackPair($local, $byPosition[$key]);
            } else {
                $freeLocal[] = $local;
            }
        }

        $freeRelease = array_filter(
            $releaseTracks,
            static fn (ReleaseTrack $track): bool => !isset($pairs[self::key($track->disc, $track->track->position)]),
        ) |> array_values(...);
        foreach (self::pairByLength($freeLocal, $freeRelease) as $pair) {
            $pairs[self::key($pair->release->disc, $pair->release->track->position)] = $pair;
        }

        return new TrackPairs(
            pairs: array_map(
                static fn (ReleaseTrack $track): ?TrackPair => $pairs[self::key($track->disc, $track->track->position)] ?? null,
                $releaseTracks,
            ) |> array_filter(...) |> array_values(...),
            unpairedLocal: array_filter(
                $localTracks,
                static fn (LocalTrack $local): bool => !array_any($pairs, static fn (TrackPair $pair): bool => $pair->local === $local),
            ) |> array_values(...),
            unpairedRelease: array_filter(
                $releaseTracks,
                static fn (ReleaseTrack $track): bool => !isset($pairs[self::key($track->disc, $track->track->position)]),
            ) |> array_values(...),
        );
    }

    /**
     * Pairs, until no more pair, each free local track and free release track that are the only
     * free track within the tolerance of the other.
     *
     * @param list<LocalTrack> $local
     * @param list<ReleaseTrack> $release
     *
     * @return list<TrackPair>
     */
    private static function pairByLength(array $local, array $release): array
    {
        $paired = [];
        do {
            $found = array_find(
                array_map(static fn (LocalTrack $track): ?TrackPair => self::onlyLengthFit($track, $release, $local), $local),
                static fn (?TrackPair $pair): bool => $pair !== null,
            );
            if ($found !== null) {
                $paired[] = $found;
                $local = array_filter($local, static fn (LocalTrack $track): bool => $track !== $found->local) |> array_values(...);
                $release = array_filter($release, static fn (ReleaseTrack $track): bool => $track !== $found->release) |> array_values(...);
            }
        } while ($found !== null);

        return $paired;
    }

    /**
     * @param list<ReleaseTrack> $release
     * @param list<LocalTrack> $local
     */
    private static function onlyLengthFit(LocalTrack $track, array $release, array $local): ?TrackPair
    {
        $fits = array_filter(
            $release,
            static fn (ReleaseTrack $candidate): bool => new TrackPair($track, $candidate)->isWithinLengthTolerance(),
        ) |> array_values(...);
        if (count($fits) !== 1) {
            return null;
        }
        $rivals = array_filter($local, static fn (LocalTrack $other): bool => new TrackPair($other, $fits[0])->isWithinLengthTolerance());

        return count($rivals) === 1 ? new TrackPair($track, $fits[0]) : null;
    }

    /** @return list<ReleaseTrack> in release order */
    private static function releaseTracks(MusicBrainzRelease $release): array
    {
        return array_map(
            static fn (MusicBrainzMedium $medium): array => array_map(
                static fn (MusicBrainzTrack $track): ReleaseTrack => new ReleaseTrack($medium->position, $track),
                $medium->tracks,
            ),
            $release->media,
        ) |> (static fn (array $media): array => array_merge(...$media));
    }

    private static function localKey(LocalTrack $track): ?string
    {
        return $track->position === null ? null : self::key($track->disc ?? 1, $track->position);
    }

    private static function key(int $disc, int $position): string
    {
        return $disc . '-' . $position;
    }
}
