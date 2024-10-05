<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Release\EngineeredIn\MasteredIn;

use MusicBrainz\Relation\Type\Area\Release\EngineeredIn\MasteredIn;
use MusicBrainz\Value\Name;

/**
 * Links a release to the area where the lacquer cutting took place. Use only when the place is unknown!
 *
 * @link https://musicbrainz.org/relationship/2d9a192c-cd30-4110-a99e-481c56a0ce70
 */
class LacquerCutIn extends MasteredIn
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lacquer cut in');
    }
}
