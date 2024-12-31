<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Recording\EngineeredIn;

use MusicBrainz\Relation\Type\Area\Recording\EngineeredIn;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the area it was recorded in. Use only when the place is unknown!
 *
 * @link https://musicbrainz.org/relationship/37ef3a0c-cac3-4172-b09b-4ca98d2857fc
 */
class RecordedIn extends EngineeredIn
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recorded in');
    }
}
