<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\OtherDatabases;

use MusicBrainz\Relation\Type\Url\Work\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This links a soundtrack work to the IMDb page for the movie, show or game of which it is a soundtrack.
 *
 * @link https://musicbrainz.org/relationship/e5c75559-4dda-452e-a900-ae375935164c
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
