<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization that promotes (or contracts out promotion) for a release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/b60d9455-aba8-4d81-b543-dbfa68044dcc
 */
class Promoted extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('promoted');
    }
}
