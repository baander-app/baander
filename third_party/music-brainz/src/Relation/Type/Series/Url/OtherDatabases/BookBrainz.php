<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Series\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the BookBrainz page for this series.
 *
 * @link https://musicbrainz.org/relationship/fe60d685-8064-4501-baab-e2de8ff52a27
 */
class BookBrainz extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('BookBrainz');
    }
}
