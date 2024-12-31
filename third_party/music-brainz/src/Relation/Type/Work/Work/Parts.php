<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work;

use MusicBrainz\Relation\Type\Work\Work;
use MusicBrainz\Value\Name;

/**
 * This indicates that a work is made up of multiple parts (such as an orchestral suite broken into movements)
 *
 * @link https://musicbrainz.org/relationship/ca8d3642-ce5f-49f8-91f2-125d72524e6a
 */
class Parts extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('parts');
    }
}
