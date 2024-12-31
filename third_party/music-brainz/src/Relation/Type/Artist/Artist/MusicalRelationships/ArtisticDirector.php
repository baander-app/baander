<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This indicates that a person is, or was, the artistic director of a group (such as a ballet/opera company).
 *
 * @link https://musicbrainz.org/relationship/ab666dde-bd85-4ac2-a209-165eaf4146a0
 */
class ArtisticDirector extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('artistic director');
    }
}
