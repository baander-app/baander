<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/9ef2ba0d-953c-43a9-b1b5-cf2ba5986406
 */
class Publishing extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('publishing');
    }
}
