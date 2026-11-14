<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A song of a library album as track pairing sees it. */
#[Exclude]
final readonly class LocalTrack
{
    /**
     * @param string $id the song's identifier; opaque here, it lets a caller map a pair back to its song
     * @param int|null $disc the tagged disc number; a track without one counts as on the first disc
     * @param int|null $position the tagged track number on its disc; a track without one pairs by length only
     * @param float|null $lengthSeconds the length measured from the audio
     * @param list<string> $artistNames the names the song credits, primary first
     * @param string|null $recordingId the MusicBrainz recording ID the song already carries
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?int $disc,
        public ?int $position,
        public ?float $lengthSeconds,
        public array $artistNames = [],
        public ?string $recordingId = null,
    ) {
    }
}
