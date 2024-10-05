<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist to its corresponding page at Songkick.
 *
 * @link https://musicbrainz.org/relationship/aac9c4bc-a5b9-30b8-9839-e3ac314c6e58
 */
class Songkick extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('songkick');
    }
}
