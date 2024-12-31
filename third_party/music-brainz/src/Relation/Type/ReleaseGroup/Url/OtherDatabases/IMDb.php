<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This links a soundtrack release group to the IMDb page for the movie, show or game of which it is a soundtrack.
 *
 * @link https://musicbrainz.org/relationship/85b0a010-3237-47c7-8476-6fcefd4761af
 */
class IMDb extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('IMDb');
    }
}
