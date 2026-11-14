<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** One artist of a MusicBrainz artist credit, as printed on a release, track or recording. */
final readonly class MusicBrainzCreditedArtist
{
    /**
     * @param string $artistId the artist's MusicBrainz ID
     * @param string $name the name as credited here, which may differ from the artist's own name
     * @param string $joinPhrase the text printed after this name, such as " feat. " or " & "; empty after the last artist
     * @param string $artistName the artist's own name in MusicBrainz
     * @param string|null $artistSortName the artist's sort name, such as "Malone, Post"
     */
    public function __construct(
        public string $artistId,
        public string $name,
        public string $joinPhrase,
        public string $artistName,
        public ?string $artistSortName,
    ) {
    }
}
