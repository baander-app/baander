<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url;

use MusicBrainz\Relation\Type\Place\Url;
use MusicBrainz\Value\Name;

/**
 * This relationship type is only used for grouping other relationship types.
 *
 * @link https://musicbrainz.org/relationship/e13a6749-086a-4c52-a03f-fce7532113ba
 */
class OnlineData extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('online data');
    }
}
