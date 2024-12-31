<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Recording\EngineeredIn;

use MusicBrainz\Relation\Type\Area\Recording\EngineeredIn;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the area it was edited in. Use only when the place is unknown!
 *
 * @link https://musicbrainz.org/relationship/e24482da-f568-46ba-a428-9fa56615c7b2
 */
class EditedIn extends EngineeredIn
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('edited in');
    }
}
