<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url;

use MusicBrainz\Relation\Type\Place\Url;
use MusicBrainz\Value\Name;

/**
 * This links an entity to the equivalent entry in another database. Please respect the whitelist.
 *
 * @link https://musicbrainz.org/relationship/87a0a644-0a69-46c0-9e48-0656b8240d89
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
