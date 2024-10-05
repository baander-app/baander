<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\ReleaseGroup;

use MusicBrainz\Relation\Type\Artist\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This links a release group to an artist, to indicate that it is a tribute album. Tribute albums often have a title in the form "A Tribute to Artist", and they consist of covers of songs by the target artist, played by other (sometimes very unknown) bands, to honor the target artist. Often they are various artist compilations, but a single artist can perform the entire tribute album.
 *
 * @link https://musicbrainz.org/relationship/5e2907db-49ec-4a48-9f11-dfb99d2603ff
 */
class Tribute extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('tribute');
    }
}
