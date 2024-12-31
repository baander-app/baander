<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the label is the copyright holder for this release, usually indicated with a © symbol.
 *
 * @link https://musicbrainz.org/relationship/2ed5a497-4f85-4b3f-831e-d341ad28c544
 */
class Copyright extends Publishing
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
