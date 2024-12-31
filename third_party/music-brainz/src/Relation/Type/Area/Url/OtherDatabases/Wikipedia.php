<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Area\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikipedia page for this area.
 *
 * @link https://musicbrainz.org/relationship/9228621d-9720-35c3-ad3f-327d789464ec
 */
class Wikipedia extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('wikipedia');
    }
}
