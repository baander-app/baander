<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link a label to its blog.
 *
 * @link https://musicbrainz.org/relationship/1b431eba-0d25-4f27-9151-1bb607f5c8f8
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
