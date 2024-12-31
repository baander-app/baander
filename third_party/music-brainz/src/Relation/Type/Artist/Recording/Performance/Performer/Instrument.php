<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance\Performer;

use MusicBrainz\Relation\Type\Artist\Recording\Performance\Performer;
use MusicBrainz\Value\Name;

/**
 * Indicates an artist that performed one or more instruments on this recording.
 *
 * @link https://musicbrainz.org/relationship/59054b12-01ac-43ee-a618-285fd397e461
 */
class Instrument extends Performer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('instrument');
    }
}
