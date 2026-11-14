<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzTrack;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A track of a looked-up release together with the disc it is on. */
#[Exclude]
final readonly class ReleaseTrack
{
    /**
     * @param int $disc the position of the track's medium in the release, from 1
     */
    public function __construct(
        public int $disc,
        public MusicBrainzTrack $track,
    ) {
    }
}
