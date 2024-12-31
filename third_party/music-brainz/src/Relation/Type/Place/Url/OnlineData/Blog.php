<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData;

use MusicBrainz\Relation\Type\Place\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This relationship type can be used to link a place to its blog.
 *
 * @link https://musicbrainz.org/relationship/e3051f32-527b-4c47-9993-71250a6cd99c
 */
class Blog extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('blog');
    }
}
