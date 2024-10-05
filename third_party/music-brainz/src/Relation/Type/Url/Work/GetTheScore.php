<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work;

use MusicBrainz\Relation\Type\Url\Work;
use MusicBrainz\Value\Name;

/**
 * This relationship type is only used for grouping other relationship types.
 *
 * @link https://musicbrainz.org/relationship/d23c710f-7657-41fe-a328-c588bcb52483
 */
class GetTheScore extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('get the score');
    }
}
