<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Url;

use MusicBrainz\Relation\Type\Area\Url;
use MusicBrainz\Value\Name;

/**
 * This links an entity to the equivalent entry in another database. Please respect the whitelist.
 *
 * @link https://musicbrainz.org/relationship/b879051b-10e6-43b4-a49a-efdecc695f02
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
