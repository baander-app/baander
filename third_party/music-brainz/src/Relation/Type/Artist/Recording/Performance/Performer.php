<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance;

use MusicBrainz\Relation\Type\Artist\Recording\Performance;
use MusicBrainz\Value\Name;

/**
 * Indicates an artist that performed on this recording.
 *
 * @link https://musicbrainz.org/relationship/628a9658-f54c-4142-b0c0-95f031b544da
 */
class Performer extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('performer');
    }
}
