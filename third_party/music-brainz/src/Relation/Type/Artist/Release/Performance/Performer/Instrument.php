<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Performance\Performer;

use MusicBrainz\Relation\Type\Artist\Release\Performance\Performer;
use MusicBrainz\Value\Name;

/**
 * Indicates an artist that performed one or more instruments on this release.
 *
 * @link https://musicbrainz.org/relationship/67555849-61e5-455b-96e3-29733f0115f5
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
