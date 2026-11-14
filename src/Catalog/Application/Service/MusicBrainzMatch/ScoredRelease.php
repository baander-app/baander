<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A looked-up release with its fit to the local album and the track pairs the score rests on. */
#[Exclude]
final readonly class ScoredRelease
{
    /**
     * @param int<0, 100> $score
     */
    public function __construct(
        public MusicBrainzRelease $release,
        public int $score,
        public TrackPairs $pairs,
    ) {
    }
}
