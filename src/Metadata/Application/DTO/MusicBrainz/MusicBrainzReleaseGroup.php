<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** The release group a release belongs to: the album that all its editions share. */
final readonly class MusicBrainzReleaseGroup
{
    /**
     * @param string $id the release group's MusicBrainz ID
     * @param string|null $primaryType such as "Album", "Single" or "EP"
     * @param string|null $firstReleaseDate the earliest date of any of its releases (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param list<string> $genres the release group's genres, most votes first; empty when the request did not ask for them
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $primaryType,
        public ?string $firstReleaseDate,
        public array $genres = [],
    ) {
    }
}
