<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Performance\Performer;

use MusicBrainz\Relation\Type\Artist\Release\Performance\Performer;
use MusicBrainz\Value\Name;

/**
 * Indicates an artist that performed vocals on this release.
 *
 * @link https://musicbrainz.org/relationship/eb10f8a0-0f4c-4dce-aa47-87bcb2bc42f3
 */
class Vocal extends Performer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('vocal');
    }
}
