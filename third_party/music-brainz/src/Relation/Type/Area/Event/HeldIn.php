<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Event;

use MusicBrainz\Relation\Type\Area\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to the area where it was held. Use only if the exact place is unknown.
 *
 * @link https://musicbrainz.org/relationship/542f8484-8bc7-3ce5-a022-747850b2b928
 */
class HeldIn extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('held in');
    }
}
