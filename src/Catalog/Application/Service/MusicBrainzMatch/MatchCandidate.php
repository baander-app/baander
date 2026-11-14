<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The release an album match stands for. Twin editions, which agree on every track's title,
 * artist credit and length and differ only in recording IDs the files cannot tell apart, form one
 * candidate: it records the earliest edition's release ID and keeps a recording ID only where
 * every edition has the same one. A match writes only the values all its editions agree on, which
 * the writer works out from `$editions`.
 */
#[Exclude]
final readonly class MatchCandidate
{
    /**
     * @param MusicBrainzRelease $release the edition whose release ID the match records: the earliest released
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

    /** The recording ID at the track's disc and position when every edition has the same one there; null otherwise. */
    public function recordingId(ReleaseTrack $track): ?string
    {
        $ids = array_map(
            static fn (MusicBrainzRelease $edition): ?string => self::trackAt($edition, $track->disc, $track->track->position)?->recordingId,
            $this->editions,
        ) |> array_unique(...);

        return count($ids) === 1 ? array_first($ids) : null;
    }

    private static function trackAt(MusicBrainzRelease $edition, int $disc, int $position): ?MusicBrainzTrack
    {
        $medium = array_find($edition->media, static fn (MusicBrainzMedium $medium): bool => $medium->position === $disc);

        return array_find($medium->tracks ?? [], static fn (MusicBrainzTrack $track): bool => $track->position === $position);
    }
}
