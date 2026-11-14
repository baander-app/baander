<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * Lists the MusicBrainz candidates for an album, song or artist, best first.
 *
 * The search takes the item's own title (an artist's name) and primary artist unless the admin
 * edited them; a blank edit counts as none. A candidate's ID is what CompareMatchQuery compares.
 */
final readonly class SearchMatchCandidatesQuery
{
    /**
     * @param string      $publicId the item's public ID, as given
     * @param string|null $title    the title, or for an artist the name, to search instead of the item's own
     * @param string|null $artist   the artist to search instead of the item's primary artist; ignored for an artist
     */
    public function __construct(
        public MatchItemType $type,
        public string $publicId,
        public ?string $title = null,
        public ?string $artist = null,
    ) {
    }
}
