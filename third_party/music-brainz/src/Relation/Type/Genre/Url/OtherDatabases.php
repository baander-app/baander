<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url;

use MusicBrainz\Relation\Type\Genre\Url;
use MusicBrainz\Value\Name;

/**
 * This links an entity to the equivalent entry in another database. Please respect the whitelist.
 *
 * @link https://musicbrainz.org/relationship/1873eeea-f2e6-4e08-a754-cc92567983ea
 */
class OtherDatabases extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('other databases');
    }
}
