<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Series\EventArtists;

use MusicBrainz\Relation\Type\Artist\Series\EventArtists;
use MusicBrainz\Value\Name;

/**
 * This relationship links a concert residency to the artist(s) who held the residency.
 *
 * @link https://musicbrainz.org/relationship/d5ea820c-4f2f-441a-878d-1715158ec111
 */
class Residency extends EventArtists
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('residency');
    }
}
