<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Misc;

use MusicBrainz\Relation\Type\Artist\Work\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates the work is dedicated to a specific person. This is most common for classical works, but also exists in other genres to a degree.
 *
 * @link https://musicbrainz.org/relationship/26131498-00e8-4136-b937-22a4be01a63d
 */
class Dedication extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('dedication');
    }
}
