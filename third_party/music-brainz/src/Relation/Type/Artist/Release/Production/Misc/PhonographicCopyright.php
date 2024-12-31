<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the artist is the phonographic copyright holder for this release, usually indicated with a ℗ symbol.
 *
 * @link https://musicbrainz.org/relationship/01d3488d-8d2a-4cff-9226-5250404db4dc
 */
class PhonographicCopyright extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('phonographic copyright');
    }
}
