<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the artist that was the licensor of this release.
 *
 * @link https://musicbrainz.org/relationship/eaaf08cf-a698-4e3b-a871-f9570f8fdab1
 */
class Licensor extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('licensor');
    }
}
