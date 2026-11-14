<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A library album as matching compares it with MusicBrainz releases. */
#[Exclude]
final readonly class LocalAlbum
{
    /**
     * @param string $artistName the name of the album's primary artist
     * @param list<LocalTrack> $tracks the album's songs, in any order
     */
    public function __construct(
        public string $title,
        public string $artistName,
        public array $tracks,
    ) {
    }

    public function trackCount(): int
    {
        return count($this->tracks);
    }
}
