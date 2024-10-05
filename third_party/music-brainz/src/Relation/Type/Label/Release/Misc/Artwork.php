<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Misc;

use MusicBrainz\Relation\Type\Label\Release\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates an agency who provided artwork for the release when no more specific information is available.
 *
 * @link https://musicbrainz.org/relationship/7fcc7ea2-968b-4e88-84bb-98700f09c116
 */
class Artwork extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('artwork');
    }
}
