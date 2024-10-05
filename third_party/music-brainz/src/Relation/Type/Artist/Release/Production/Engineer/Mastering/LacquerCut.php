<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer\Mastering;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer\Mastering;
use MusicBrainz\Value\Name;

/**
 * Links a release to the engineer who did the lacquer cutting for it.
 *
 * @link https://musicbrainz.org/relationship/904e57f3-cbbc-43ab-8798-13e710e400d3
 */
class LacquerCut extends Mastering
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lacquer cut');
    }
}
