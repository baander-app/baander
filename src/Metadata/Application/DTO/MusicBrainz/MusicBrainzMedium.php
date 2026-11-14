<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A medium of a looked-up release, such as one disc, with its tracks. */
final readonly class MusicBrainzMedium
{
    /**
     * @param int $position the medium's 1-based position in the release (the disc number)
     * @param string|null $format such as "CD" or "Digital Media"
     * @param string|null $title the medium's own title, if it has one
     * @param list<MusicBrainzTrack> $tracks in track order
     */
    public function __construct(
        public int $position,
        public ?string $format,
        public ?string $title,
        public array $tracks,
    ) {
    }
}
