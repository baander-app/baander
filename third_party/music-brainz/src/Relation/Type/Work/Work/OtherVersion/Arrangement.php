<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work\OtherVersion;

use MusicBrainz\Relation\Type\Work\Work\OtherVersion;
use MusicBrainz\Value\Name;

/**
 * This links two works where one work is an arrangement of the other.
 *
 * @link https://musicbrainz.org/relationship/51975ed8-bbfa-486b-9f28-5947f4370299
 */
class Arrangement extends OtherVersion
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('arrangement');
    }
}
