<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Url;

use MusicBrainz\Relation\Type\Instrument\Url;
use MusicBrainz\Value\Name;

/**
 * This links an entity to the equivalent entry in another database. Please respect the whitelist.
 *
 * @link https://musicbrainz.org/relationship/41930af2-cb94-488d-a4f0-d232f6ef391a
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
