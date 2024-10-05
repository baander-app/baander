<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance;

use MusicBrainz\Relation\Type\Artist\Recording\Performance;
use MusicBrainz\Value\Name;

/**
 * This indicates the chorus master of a choir which performed on this recording.
 *
 * @link https://musicbrainz.org/relationship/45115945-597e-4cb9-852f-4e6ba583fcc8
 */
class ChorusMaster extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('chorus master');
    }
}
