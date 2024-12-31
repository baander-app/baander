<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance;

use MusicBrainz\Relation\Type\Artist\Recording\Performance;
use MusicBrainz\Value\Name;

/**
 * Indicates an orchestra that performed on this recording.
 *
 * @link https://musicbrainz.org/relationship/3b6616c5-88ba-4341-b4ee-81ce1e6d7ebb
 */
class PerformingOrchestra extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('performing orchestra');
    }
}
