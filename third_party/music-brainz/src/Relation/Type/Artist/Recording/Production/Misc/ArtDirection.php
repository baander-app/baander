<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates that a person or agency did the art direction for the recording.
 *
 * @link https://musicbrainz.org/relationship/9aae9a3d-7cc2-4eee-881d-b8b73d0ceef3
 */
class ArtDirection extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('art direction');
    }
}
