<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Series;

use MusicBrainz\Relation\Type\Series\Series;
use MusicBrainz\Value\Name;

/**
 * Links an award series to the award ceremony series of events where it's announced and/or awarded.
 *
 * @link https://musicbrainz.org/relationship/2c0799ce-06f5-4c14-81d1-c1465590e45f
 */
class AwardCeremony extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('award ceremony');
    }
}
