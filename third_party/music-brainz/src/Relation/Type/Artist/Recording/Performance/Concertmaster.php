<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance;

use MusicBrainz\Relation\Type\Artist\Recording\Performance;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who was the concertmaster/leader for an orchestra or band on this recording.
 *
 * @link https://musicbrainz.org/relationship/ffeaa74f-8295-45ee-a2f2-7c0cc1f73b1e
 */
class Concertmaster extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('concertmaster');
    }
}
