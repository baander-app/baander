<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the artist is the copyright holder for this release, usually indicated with a © symbol.
 *
 * @link https://musicbrainz.org/relationship/730b5251-7432-4896-8fc6-e1cba943bfe1
 */
class Copyright extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('copyright');
    }
}
