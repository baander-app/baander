<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the BookBrainz page for this release group.
 *
 * @link https://musicbrainz.org/relationship/27cfc95c-d368-45a9-ae0d-308c274c2017
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
