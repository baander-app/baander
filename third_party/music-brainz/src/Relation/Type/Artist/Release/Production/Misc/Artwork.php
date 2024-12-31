<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who provided artwork for the release when no more specific information is available.
 *
 * @link https://musicbrainz.org/relationship/5acef56d-e676-4b4d-a581-db5d36afd213
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
