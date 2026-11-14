<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A local track and the release track it pairs with. */
#[Exclude]
final readonly class TrackPair
{
    public function __construct(
        public LocalTrack $local,
        public ReleaseTrack $release,
    ) {
    }

    /** How many seconds the two lengths differ by; null when either length is unknown. */
    public function lengthDifferenceSeconds(): ?float
    {
        $releaseMs = $this->release->track->lengthMs;
        if ($this->local->lengthSeconds === null || $releaseMs === null) {
            return null;
        }

        return abs($this->local->lengthSeconds - $releaseMs / 1000);
    }

    /** Whether both lengths are known and differ by no more than the pairing tolerance. */
    public function isWithinLengthTolerance(): bool
    {
        $difference = $this->lengthDifferenceSeconds();

        return $difference !== null && $difference <= TrackPairing::LENGTH_TOLERANCE_SECONDS;
    }
}
