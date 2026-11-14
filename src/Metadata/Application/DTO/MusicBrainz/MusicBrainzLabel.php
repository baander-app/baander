<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A label a release came out on, with the release's catalogue number there. */
final readonly class MusicBrainzLabel
{
    /**
     * @param string|null $labelId the label's MusicBrainz ID; null when the release names only a catalogue number
     * @param string|null $name the label's name
     * @param string|null $catalogNumber the release's catalogue number on this label
     */
    public function __construct(
        public ?string $labelId,
        public ?string $name,
        public ?string $catalogNumber,
    ) {
    }
}
