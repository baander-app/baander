<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist (generally a person) was the founder of a group.
 *
 * @link https://musicbrainz.org/relationship/6ed4bfc4-0a0d-44c0-b025-b7fc4d900b67
 */
class Founder extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('founder');
    }
}
