<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** How the tracks of a local album and a release pair up, with the tracks left over on each side. */
#[Exclude]
final readonly class TrackPairs
{
    /**
     * @param list<TrackPair> $pairs in release order
     * @param list<LocalTrack> $unpairedLocal in the order the album listed them
     * @param list<ReleaseTrack> $unpairedRelease in release order
     */
    public function __construct(
        public array $pairs,
        public array $unpairedLocal,
        public array $unpairedRelease,
    ) {
    }
}
