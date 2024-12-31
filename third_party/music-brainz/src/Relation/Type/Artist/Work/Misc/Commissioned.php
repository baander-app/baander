<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Misc;

use MusicBrainz\Relation\Type\Artist\Work\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates that the artist commissioned the work. This is most common for classical works, but also exists in other genres to a degree.
 *
 * @link https://musicbrainz.org/relationship/95f0213a-dbe0-4d36-8036-9782e425e98a
 */
class Commissioned extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('commissioned');
    }
}
