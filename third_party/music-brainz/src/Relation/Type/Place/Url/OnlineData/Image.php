<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData;

use MusicBrainz\Relation\Type\Place\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * Indicates a pictorial image (JPEG, GIF, PNG) of a place
 *
 * @link https://musicbrainz.org/relationship/68a4537c-f2a6-49b8-81c5-82a62b0976b7
 */
class Image extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('image');
    }
}
