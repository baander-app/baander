<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OnlineData;

use MusicBrainz\Relation\Type\Artist\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link an artist to their blog.
 *
 * @link https://musicbrainz.org/relationship/eb535226-f8ca-499d-9b18-6a144df4ae6f
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
