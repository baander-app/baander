<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url;

use MusicBrainz\Relation\Type\Genre\Url;
use MusicBrainz\Value\Name;

/**
 * This relationship type is only used for grouping other relationship types.
 *
 * @link https://musicbrainz.org/relationship/ec1bf02b-a941-4a5e-b0f1-ae97093061dd
 */
class GetTheMusic extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('get the music');
    }
}
