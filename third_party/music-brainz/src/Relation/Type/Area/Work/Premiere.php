<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Work;

use MusicBrainz\Relation\Type\Area\Work;
use MusicBrainz\Value\Name;

/**
 * Indicates the area where the work had its first performance.
 *
 * @link https://musicbrainz.org/relationship/c6bd908f-41f1-4ff3-83f3-df514278d994
 */
class Premiere extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('premiere');
    }
}
