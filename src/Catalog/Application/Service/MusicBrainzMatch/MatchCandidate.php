<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The release an album match stands for. Twin editions, which hold the same tracks in the same order
 * with the same titles, artist credits and lengths and differ only in recording IDs the files cannot
 * tell apart, or in how their discs split the tracks, form one candidate: it records the release ID
 * of the edition that keeps the most songs at their own disc and number, the earliest released of
 * those, and keeps a recording ID only where every edition has the same one at that place in the
 * track order. A match writes only the values
 * all its editions agree on, which the writer works out from `$editions`.
 */
#[Exclude]
final readonly class MatchCandidate
{
    /**
     * @param MusicBrainzRelease $release the edition whose release ID the match records: of the editions that keep the
     *                                   most songs at their own disc and number, the earliest released
     * @param int<0, 100> $score the best score among the editions
     * @param TrackPairs $pairs the local tracks paired with the tracks of `$release`; a paired track's own
     *                         recording ID belongs to `$release` alone, so take recording IDs from recordingId()
     * @param non-empty-list<MusicBrainzRelease> $editions every edition the candidate stands for, earliest released first; `$release` alone when it has no twin
     */
    public function __construct(
        public MusicBrainzRelease $release,
        public int $score,
        public TrackPairs $pairs,
        public array $editions,
    ) {
    }

    /**
     * The recording ID of a track of `$release` when every edition has the same one at that place in
     * the track order; null otherwise.
     */
    public function recordingId(ReleaseTrack $track): ?string
    {
        $place = $this->placeOf($track);
        if ($place === null) {
            return null;
        }
        $ids = array_map(
            static fn (MusicBrainzRelease $edition): ?string => ($edition->tracks()[$place] ?? null)?->recordingId,
            $this->editions,
        ) |> array_unique(...);

        return count($ids) === 1 ? array_first($ids) : null;
    }

    /** The track's index in the track order of `$release`, across its discs; null when it is not one of its tracks. */
    private function placeOf(ReleaseTrack $track): ?int
    {
        $place = 0;
        foreach ($this->release->media as $medium) {
            foreach ($medium->tracks as $releaseTrack) {
                if ($medium->position === $track->disc && $releaseTrack->position === $track->track->position) {
                    return $place;
                }
                ++$place;
            }
        }

        return null;
    }
}
