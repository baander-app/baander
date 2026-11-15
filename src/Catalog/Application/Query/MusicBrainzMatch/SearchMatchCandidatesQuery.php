<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * Lists the MusicBrainz candidates for an album, song or artist, best first.
 *
 * The search takes the item's own title (an artist's name) and primary artist unless the admin
 * edited them. A blank title counts as no edit; a blank artist searches by the title alone, as
 * for a compilation or a wrongly tagged album. A candidate's ID is what CompareMatchQuery compares.
 *
 * An album's own title is searched a second time when it finds no release and ends in a
 * comma-separated list in square brackets, such as "[Reprise Records,9362-49433-2,EU]": the
 * second search leaves the list out, and the answer names the shortened title and scores its
 * candidates against it. A title the admin gave is searched as given, once. The album's stored
 * title stays as it is either way.
 */
final readonly class SearchMatchCandidatesQuery
{
    /**
     * @param string      $publicId the item's public ID, as given
     * @param string|null $title    the title, or for an artist the name, to search instead of the item's own
     * @param string|null $artist   the artist to search instead of the item's primary artist: null for the item's own,
     *                              a blank string to search without an artist; ignored for an artist
     */
    public function __construct(
        public MatchItemType $type,
        public string $publicId,
        public ?string $title = null,
        public ?string $artist = null,
    ) {
    }
}
