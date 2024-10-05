<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Label\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the BookBrainz page for this label.
 *
 * @link https://musicbrainz.org/relationship/b7be2ca4-bdb7-4d87-9619-f2fa50120409
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
