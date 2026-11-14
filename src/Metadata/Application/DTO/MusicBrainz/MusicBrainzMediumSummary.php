<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A medium of a release search hit: its format and the number of tracks on it. */
final readonly class MusicBrainzMediumSummary
{
    /**
     * @param string|null $format such as "CD" or "Digital Media"
     */
    public function __construct(
        public ?string $format,
        public int $trackCount,
    ) {
    }
}
