<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Work;

use MusicBrainz\Relation\Type\Label\Work;
use MusicBrainz\Value\Name;

/**
 * This indicates that the organisation commissioned the work. This is most common for classical works, but also exists in other genres to a degree.
 *
 * @link https://musicbrainz.org/relationship/74f8e1b8-b91f-425d-bc36-f8cc09d4f456
 */
class Commissioned extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('commissioned');
    }
}
